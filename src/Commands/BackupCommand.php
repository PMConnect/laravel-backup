<?php

namespace Spatie\Backup\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\InputOption;
use ZipArchive;

class BackupCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'backup:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the backup';

    /**
     * Files that will be remove at the end of the command.
     *
     * @var array
     */
    protected $temporaryFiles = [];

    /**
     * Execute the console command.
     *
     * @return bool
     */
    public function fire()
    {
        $this->guardAgainstInvalidOptions();

        $this->info('Start backing up');

        $files = $this->getAllFilesToBeBackedUp();

        if (count($files) == 0) {
            $this->info('Nothing to backup');

            return true;
        }

        $backupZipFile = $this->createZip($files);

        $this->temporaryFiles[] = $backupZipFile;

        if (filesize($backupZipFile) == 0) {
            $this->warn('The zipfile that will be backupped has a filesize of zero.');
        }

        foreach ($this->getTargetFileSystems() as $fileSystem) {
            $this->copyFileToFileSystem($backupZipFile, $fileSystem);
        }

        $this->removeTemporaryFiles();

        $this->info('Backup successfully completed');

        return true;
    }

    /**
     * Return an array with path to files that should be backed up.
     *
     * @return array
     */
    protected function getAllFilesToBeBackedUp()
    {
        $files = [];

        $filesToAdd =  $this->getDatabaseDump();

        foreach($filesToAdd as $key => $file){
            $files[] = ['realFile' =>$file, 'fileInZip' => $key.'_backup.sql'];
        }

        return $files;
    }

    /**
     * Create a zip for the given files.
     *
     * @param $files
     *
     * @return string
     */
    protected function createZip($files)
    {
        $this->comment('Start zipping '.count($files).' files...');

        $tempZipFile = tempnam(sys_get_temp_dir(), 'laravel-backup-zip');

        $zip = new ZipArchive();
        $zip->open($tempZipFile, ZipArchive::CREATE);

        foreach ($files as $file) {
            if (file_exists($file['realFile'])) {
                $zip->addFile($file['realFile'], $file['fileInZip']);
            }
        }

        $zip->close();

        $this->comment('Zip created!');

        return $tempZipFile;
    }

    /**
     * Copy the given file on the given disk to the given destination.
     *
     * @param string                                      $file
     * @param \Illuminate\Contracts\Filesystem\Filesystem $disk
     * @param string                                      $destination
     * @param bool                                        $addIgnoreFile
     */
    protected function copyFile($file, $disk, $destination, $addIgnoreFile = false)
    {
        $destinationDirectory = dirname($destination);

        if ($destinationDirectory != '.') {
            $disk->makeDirectory($destinationDirectory);
        }

        if ($addIgnoreFile) {
            $this->writeIgnoreFile($disk, $destinationDirectory);
        }

        /*
         * The file could be quite large. Use a stream to copy it
         * to the target disk to avoid memory problems
         */
        try{
            $disk->getDriver()->writeStream($destination, fopen($file, 'r+'));
        }catch(\Exception $e){

        }
    }

    /**
     * Get the filesystems to where the database should be dumped.
     *
     * @return array
     */
    protected function getTargetFileSystems()
    {
        $fileSystems = config('laravel-backup.destination.filesystem');

        if (is_array($fileSystems)) {
            return $fileSystems;
        }

        return [$fileSystems];
    }

    /**
     * Write an ignore-file on the given disk in the given directory.
     *
     * @param \Illuminate\Contracts\Filesystem\Filesystem $disk
     * @param string                                      $dumpDirectory
     */
    protected function writeIgnoreFile($disk, $dumpDirectory)
    {
        $gitIgnoreContents = '*'.PHP_EOL.'!.gitignore';
        $disk->put($dumpDirectory.'/.gitignore', $gitIgnoreContents);
    }

    /**
     * Determine the name of the zip that contains the backup.
     *
     * @return string
     */
    protected function getBackupDestinationFileName()
    {
        $backupDirectory = config('laravel-backup.destination.path');
        $backupFilename = $this->getPrefix().date('YmdHis').$this->getSuffix().'.zip';

        $destination = $backupDirectory;

        if ($destination != '') {
            $destination .= '/';
        }

        $destination .= $backupFilename;

        return $destination;
    }

    /**
     * Get the prefix to be used in the filename of the backup file.
     *
     * @return string
     */
    public function getPrefix()
    {
        if ($this->option('prefix') != '') {
            return $this->option('prefix');
        }

        return config('laravel-backup.destination.prefix');
    }

    /**
     * Get the suffix to be used in the filename of the backup file.
     *
     * @return string
     */
    public function getSuffix()
    {
        if ($this->option('suffix') != '') {
            return $this->option('suffix');
        }

        return config('laravel-backup.destination.suffix');
    }

    /**
     * Copy the given file to given filesystem.
     *
     * @param string $file
     * @param $fileSystem
     */
    public function copyFileToFileSystem($file, $fileSystem)
    {
        $this->comment('Start uploading backup to '.$fileSystem.'-filesystem...');

        $disk = Storage::disk($fileSystem);

        $backupFilename = $this->getBackupDestinationFileName();

        $this->copyFile($file, $disk, $backupFilename, $fileSystem == 'local');

        $this->comment('Backup stored on '.$fileSystem.'-filesystem in file "'.$backupFilename.'"');
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['only-db', null, InputOption::VALUE_NONE, 'Only backup the database.'],
            ['only-files', null, InputOption::VALUE_NONE, 'Only backup the files.'],
            ['prefix', null, InputOption::VALUE_REQUIRED, 'The name of the zip file will get prefixed with this string.'],
            ['suffix', null, InputOption::VALUE_REQUIRED, 'The name of the zip file will get suffixed with this string.'],
        ];
    }

    /**
     * Get a dump of the db.
     * @return string
     * @throws Exception
     * @throws \Exception
     */
    protected function getDatabaseDump()
    {

        File::cleanDirectory(storage_path()."/app/backups/");

        $databaseBackupHandler = app()->make('Spatie\Backup\BackupHandlers\Database\DatabaseBackupHandler');

        $filesToBeBackedUp = array();

        $databases = [
            "games_old",
            "fitness_old",
            "food_old",
            "ifitness_logs",
            "games_logs",
            "PMSystem",
            "wwe_new",
            "mobile_support",

        ];

        foreach ($databases as $db) {

            $newFile=storage_path()."/app/backups/".'laravel-backup-db' . $db.uniqid();

            touch($newFile);

            $tempFile = $newFile;

            $status = $databaseBackupHandler->getDatabase($db)->dump($tempFile);

            if (!$status || filesize($tempFile) == 0) {
                throw new \Exception('Could not create backup of db');
            }

            $filesToBeBackedUp[$db] = $tempFile;
            $this->temporaryFiles[] = $tempFile;
        }

        if (count($filesToBeBackedUp) < 1) {
            throw new \Exception('could not backup db');
        }

        $this->comment('Database dumped');

        return $filesToBeBackedUp;
    }

    /**
     * @throws \Exception
     */
    protected function guardAgainstInvalidOptions()
    {
        if ($this->option('only-db') && $this->option('only-files')) {
            throw new \Exception('cannot use only-db and only-files together');
        }
    }

    /**
     * Remove temporary files.
     */
    protected function removeTemporaryFiles()
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            if (file_exists($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }
}
