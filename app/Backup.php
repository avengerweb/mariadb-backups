<?php

namespace App;


use Carbon\Carbon;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Symfony\Component\Process\Process;

class Backup
{
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

        // mariabackup --backup --target-dir=/mnt/storage/mariadb/full1 --user=root
        $process = new Process([
            'mariabackup',
            '--backup',
            '--target-dir=' . $week . DIRECTORY_SEPARATOR . self::FULL_BACKUP,
            '--user=root'
        ]);

        $this->log("Execute: " . $process->getCommandLine());

        $code = $process->run(function ($type, $buffer) {
            if (Process::ERR === $type) {
                echo 'ERR > ' . $buffer;
            } else {
                echo 'OUT > ' . $buffer;
            }
        });

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

        // mariabackup --backup --target-dir=/mnt/storage/mariadb/inc1_3 --incremental-basedir=/mnt/storage/mariadb/full1 --user=root
        $process = new Process([
            'mariabackup',
            '--backup',
            '--target-dir=' . $week . DIRECTORY_SEPARATOR . $this->today(),
            '--incremental-basedir=' . $week . DIRECTORY_SEPARATOR . self::FULL_BACKUP,
            '--user=root'
        ]);

        $this->log("Execute: " . $process->getCommandLine());

        $code = $process->run(function ($type, $buffer) {
            if (Process::ERR === $type) {
                echo 'ERR > ' . $buffer;
            } else {
                echo 'OUT > ' . $buffer;
            }
        });

        return $code == 0;
    }
}
