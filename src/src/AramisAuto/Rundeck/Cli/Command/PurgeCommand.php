<?php

namespace AramisAuto\Rundeck\Cli\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Stopwatch\Stopwatch;

class PurgeCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('purge')
            ->setDescription('Deletes old Rundeck execution logs in database and filesystem')
            ->addArgument('keep', InputArgument::REQUIRED, 'Number of log days to keep from now')
            ->addOption(
                'rundeck-config',
                null,
                InputOption::VALUE_REQUIRED,
                "Path to Rundeck's rundeck-config.properties file", '/etc/rundeck/rundeck-config.properties'
            )
            ->addOption('progress', null, InputOption::VALUE_NONE, 'Display progress bar')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do not perform purge, just show what would be purged'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Sanity check
        if (!is_readable($input->getOption('rundeck-config'))) {
            throw new \RuntimeException(
                sprintf('Rundeck properties files is not readable - {path: "%s"}', $input->getOption('rundeck-config'))
            );
        }

        // Guess database driver
        $contentsRundeckConfig = file_get_contents($input->getOption('rundeck-config'));
        preg_match('/dataSource.url ?= ?jdbc:(\w+):.*/', $contentsRundeckConfig, $matches);
        if (!count($matches)) {
            throw new \RuntimeException(
                sprintf(
                    'Configuration does not have a dataSource.url - {path: "%s"}',
                    $input->getOption('rundeck-config')
                )
            );
        }

        // Call appropriate purge implementation
        $method = 'purge'.ucfirst(strtolower($matches[1]));
        if (!method_exists($this, $method)) {
            throw new \BadMethodCallException(sprintf('Unsupported database - {database: "%s"}', $matches[1]));
        }
        call_user_func(array($this, $method), $contentsRundeckConfig, $input, $output);
    }

    private function purgeMysql($contentsRundeckConfig, InputInterface $input, OutputInterface $output)
    {
        // Extract database connection informations
        $connectionInformations = array('hostname' => null, 'port' => 3306, 'database' => null, 'username' => null, 'password' => null);
        $matches = array();
        preg_match('/dataSource.url ?= ?jdbc:(\w+):.*/', $contentsRundeckConfig, $matches);
        if (!count($matches)) {
            throw new \RuntimeException(
                sprintf(
                    'Configuration does not have a dataSource.url - {path: "%s"}',
                    $input->getOption('rundeck-config')
                )
            );
        } elseif ($matches[1] !== 'mysql') {
            throw new \RuntimeException(sprintf('Unsupported database - {database: "%s"}', $matches[1]));
        } else {
            // Hostname and database
            $url = parse_url(substr($matches[0], strpos($matches[0], 'mysql://')));
            if (!$url) {
                throw new \RuntimeException(
                    sprintf(
                        'Impossible to parse DSN - {path: "%s"}', $input->getOption('rundeck-config')
                    )
                );
            }
            $connectionInformations['port'] = (isset($url['port']) ? $url['port'] : $connectionInformations['port']);
            $connectionInformations['hostname'] = $url['host'];
            $connectionInformations['database'] = substr($url['path'], 1);

            // Username
            preg_match('/dataSource.username ?= ?(.*)/', $contentsRundeckConfig, $matches);
            if (count($matches)) {
                $connectionInformations['username'] = $matches[1];
            }

            // Password
            preg_match('/dataSource.password ?= ?(.*)/', $contentsRundeckConfig, $matches);
            if (count($matches)) {
                $connectionInformations['password'] = $matches[1];
            }
        }

        // Timer
        $stopwatch = new Stopwatch();
        $stopwatch->start('purge');

        // Log
        $output->writeln(
            sprintf(
                '<info>Purging records from database</info> - {database: "mysql", hostname: "%s", port: "%s", username: "%s", name": "%s"}',
                $connectionInformations['hostname'],
                $connectionInformations['port'],
                $connectionInformations['username'],
                $connectionInformations['database']
            )
        );

        // Connect to database
        $pdo = new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                $connectionInformations['hostname'],
                $connectionInformations['port'],
                $connectionInformations['database']
            ),
            $connectionInformations['username'],
            $connectionInformations['password']
        );

        // Create missing indexes
        $indexes = array(
            array('base_report', 'jc_exec_id'),
            array('workflow_workflow_step', 'workflow_commands_id')
        );
        $sqlIndexExists = 'select count(*) as indexExists from information_schema.statistics where table_name = :table and index_name = :index';
        $sqlIndexCreate = 'ALTER TABLE `%s` ADD INDEX (`%s`)';
        foreach ($indexes as $index) {
            $stmt = $pdo->prepare($sqlIndexExists);
            $stmt->execute(array(':table' => $index[0], ':index' => $index[1]));
            $res = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$res['indexExists']) {
                $pdo->exec(sprintf($sqlIndexCreate, $index[0], $index[1]));
                $output->writeln(
                    sprintf(
                        '<info>Created index</info> - {table: "%s", index: "%s"}',
                        $index[0],
                        $index[1]
                    )
                );
            }
        }

        // Get list of logs that must be deleted
        $sqlGetLogs = <<<EOT
SELECT
    a.id "br_id",
    b.id "ex_id",
    b.outputfilepath "ex_logpfad",
    b.workflow_id "ex_wfid",
    group_concat(c.workflow_step_id) "ws_stepids"
FROM
    base_report a 
LEFT JOIN execution b ON a.JC_EXEC_ID = b.ID
LEFT JOIN workflow_workflow_step c on b.workflow_id = c.workflow_commands_id
WHERE
    datediff(now(), a.date_completed) > :keep
group by
    a.id,
    b.id,
    b.outputfilepath,
    b.workflow_id
order by a.id;
EOT;
        $stmt = $pdo->prepare($sqlGetLogs);
        if (!$stmt->execute(array('keep' => $input->getArgument('keep')))) {
            throw new \PDOException('An error occured while executing query');
        }

        // Log
        $output->writeln(
            sprintf(
                '<info>Identified execution logs to be purged</info> - {count: %d}',
                $stmt->rowCount()
            )
        );

        // Progress bar
        if ($input->getOption('progress') && $stmt->rowCount() > 0) {
            $progressGlobal = $this->getHelperSet()->get('progress');
            $progressGlobal->start($output, $stmt->rowCount());
            $progressGlobal->setCurrent(0);
        }

        // Delete data
        $fs = new Filesystem();
        while ($res = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (!$input->getOption('dry-run')) {
                // Delete database entries
                $pdo->exec("DELETE FROM base_report WHERE id = ".$res['br_id']);
                $pdo->exec("DELETE FROM execution WHERE id = ".$res['ex_id']);
                $pdo->exec("DELETE FROM workflow WHERE id = ".$res['ex_wfid']);
                $pdo->exec("DELETE FROM workflow_step WHERE id = " . $res['ex_wfid']);
                $pdo->exec("DELETE FROM workflow_workflow_step WHERE id = ".$res['ex_wfid']);
                $pdo->exec(sprintf("DELETE FROM workflow_step WHERE id = IN(%s)", $res['ws_stepids']));
                // Remove log from filesystem
                $fs->remove($res['ex_logpfad']);
            }

            // Log
            if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
                if ($input->getOption('progress')) {
                    $output->writeln("");
                }
                $output->writeln(
                    sprintf(
                        '<info>Purged execution log</info> - {logfile: "%s", base_report_id: %s, "execution_id": %s, workflow_id: %s, workflow_step_ids: "%s"}',
                        $res['ex_logpfad'],
                        $res['br_id'],
                        $res['ex_id'],
                        $res['ex_wfid'],
                        $res['ws_stepids']
                    )
                );
            }

            // Advance progress
            if ($input->getOption('progress')) {
                $progressGlobal->advance();
            }
        }
            
        $pdo->exec("DELETE FROM workflow WHERE id NOT in (SELECT id FROM execution) AND id NOT IN (SELECT distinct workflow_id FROM scheduled_execution) AND id NOT IN (SELECT DISTINCT workflow_id FROM execution)");
        $pdo->exec("DELETE FROM workflow_step WHERE id NOT IN (SELECT workflow_step_id FROM workflow_workflow_step)");


        // Close progress bar
        if ($input->getOption('progress')) {
            $progressGlobal->finish();
        }

        // Stop timer
        $timer = $stopwatch->stop('purge');

        // Log
        $output->writeln(
            sprintf(
                '<info>Purge complete</info> - {duration: %d, dryrun: %d}',
                $timer->getDuration(),
                $input->getOption('dry-run')
            )
        );
    }
}
