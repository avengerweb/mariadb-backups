<?php

namespace App;


use Carbon\Carbon;
use GuzzleHttp\Client;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Symfony\Component\Process\Process;

class Backup
{
    const MAX_HOOK_TRIES = 3;
    var string $path;
    const FULL_BACKUP = "full_weekly";

    private Filesystem $filesystem;

    /**
     * Backup constructor.
     * @param string $path
     * @throws \Exception
     */
    public function __construct(string $path)
    {
        $this->path = $path;

        if (!$this->path) {
            throw new \Exception("Provide directory to backups");
        }

        $this->filesystem = new Filesystem(new Local($this->path));
    }

    public function checkAndBackup()
    {
        if (!$this->hasFullBackupForThisWeek()) {
            $this->log('Create weekly backup');
            if (!$this->createFullWeeklyBackup()) {
                $this->log('Error, backup not created');
            }
            return;
        }

        if (!$this->hasBackupForToday()) {
            $this->log('Create today backup');
            if (!$this->createTodayBackup()) {
                $this->log('Error, backup not created');
            }
        } else {
            $this->log('Backup for today already exists!');
        }
    }

    private function hasFullBackupForThisWeek()
    {
        $week = $this->getWeekDirectory();
        if (!$this->filesystem->has($week)) {
            $this->filesystem->createDir($week);
        }

        return $this->filesystem->has($week . DIRECTORY_SEPARATOR . self::FULL_BACKUP);
    }

    protected function getWeekDirectory(): string
    {
        $week = Carbon::now()->startOfWeek();
        return $week->format("Y-m-d") . '-to-' . $week->endOfWeek()->format("Y-m-d");
    }

    private function createFullWeeklyBackup(): bool
    {
        $week = $this->path . DIRECTORY_SEPARATOR . $this->getWeekDirectory();
        $target = $week . DIRECTORY_SEPARATOR . self::FULL_BACKUP;

        // mariabackup --backup --target-dir=/mnt/storage/mariadb/full1 --user=root
        $process = new Process([
            'mariabackup',
            '--backup',
            '--target-dir=' . $week . DIRECTORY_SEPARATOR . self::FULL_BACKUP,
            '--user=root'
        ]);

        $process->setTimeout(60 * 60);

        $this->log("Execute: " . $process->getCommandLine());

        $code = $process->run(function ($type, $buffer) {
            echo $buffer;
        });

        try {
            $this->sendReport($target, $code == 0, true);
        } catch (\Exception $e) {
            $this->log('Backup completed but report can be sent: '.$e->getMessage()."\n".$e->getTraceAsString());
        }

        return $code == 0;
    }

    protected function log(string $log)
    {
        echo $log . PHP_EOL;
    }

    protected function today(): string
    {
        return Carbon::now()->format("Y-m-d");
    }

    private function hasBackupForToday()
    {
        return $this->filesystem->has($this->getWeekDirectory() . DIRECTORY_SEPARATOR . $this->today());
    }

    private function createTodayBackup(): bool
    {
        $week = $this->path . DIRECTORY_SEPARATOR . $this->getWeekDirectory();
        $target = $week . DIRECTORY_SEPARATOR . $this->today();

        // mariabackup --backup --target-dir=/mnt/storage/mariadb/inc1_3 --incremental-basedir=/mnt/storage/mariadb/full1 --user=root
        $process = new Process([
            'mariabackup',
            '--backup',
            '--target-dir=' . $target,
            '--incremental-basedir=' . $week . DIRECTORY_SEPARATOR . self::FULL_BACKUP,
            '--user=root'
        ]);

        $process->setTimeout(60 * 60);

        $this->log("Execute: " . $process->getCommandLine());

        $code = $process->run(function ($type, $buffer) {
            echo $buffer;
        });

        try {
            $this->sendReport($target, $code == 0, false);
        } catch (\Exception $e) {
            $this->log('Backup completed but report can be sent: '.$e->getMessage()."\n".$e->getTraceAsString());
        }

        return $code == 0;
    }

    public function sendReport(string $pathToBackup, bool $isDone, bool $isFull): void
    {
        $url = $_ENV['BACKUP_HOOK_URL'] ?? null;
        if (! $url) {
            $this->log("No report will be sent, please set BACKUP_HOOK_URL");
            return;
        }

        $fullPath = $this->path.DIRECTORY_SEPARATOR.$pathToBackup;

        $report = [
            'week' => $this->getWeekDirectory(),
            'date' => $this->today(),
            'is_full' => $isFull,
            'success' => $isDone,
            'space' => [
                'free' => disk_free_space($fullPath),
                'total' => disk_total_space($fullPath),
            ],
        ];

        if ($isDone) {
            try {
                $report['meta'] = $this->parseMeta($pathToBackup.DIRECTORY_SEPARATOR.'xtrabackup_info');
            } catch (\Exception $exception) {
                $this->log("Couldn't parse meta information.");
            }
        }

        $client = new Client();

        $tries = 0;

        while (true) {
            try {
                $client->post($_ENV['BACKUP_HOOK_URL'], ['json' => $report]);
                break;
            } catch (\Exception $exception) {
                if ($tries > self::MAX_HOOK_TRIES) {
                    throw $exception;
                }

                $tries++;
            }
        }
    }

    private function parseMeta(string $path): array
    {
        $content = $this->filesystem->get($path);

        return array_map(function (string $line) {
            $data = explode("=", $line, 2);
            return ['key' => trim($data[0]), 'value' => trim($data[1] ?? "")];
        }, explode("\n", $content->read()));
    }
}
