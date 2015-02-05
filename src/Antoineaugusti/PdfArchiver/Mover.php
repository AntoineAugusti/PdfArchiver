<?php namespace Antoineaugusti\PdfArchiver;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\MountManager;
use League\Flysystem\Plugin\ListWith;

class Mover {

	/**
	 * The manager instance with a local and a remote filesystem
	 *
	 * @var \League\Flysystem\MountManager
	 */
	private $manager;

	/**
	 * The local filesystem
	 *
	 * @var \League\Flysystem\Filesystem
	 */
	private $localFilesystem;

	/**
	 * The locale filesystem root path
	 *
	 * @var string
	 */
	private $localRootPath;

	public function __construct(AdapterInterface $local, AdapterInterface $remote)
	{

		// Remember the local root path
		$this->localRootPath = $local->getPathPrefix();

		// Create the local filesystem
		$local = new Filesystem($local);
		$local->addPlugin(new ListWith);
		$this->localFilesystem = $local;

		// The remote filesystem
		$remote = new Filesystem($remote);

		// Create the manager with the local and remote filesystems
		$this->manager = new MountManager(compact('local', 'remote'));
	}

	/**
	 * Run the script from the given path of the local filesystem
	 *
	 * @param  string $path
	 */
	public function run($path = '.')
	{
		$this->processDirectory($path);
	}

	/**
	 * Process recursively the given directory
	 *
	 * @param  string $path
	 */
	private function processDirectory($path)
	{
		if ($this->hasMakefile($path) AND $this->hasPdfFolder($path))
		{
			echo "Proccessing directory ".$path.PHP_EOL;

			$this->callMakefile($path);
			$this->movePdfFilesToRemote($path.'/pdf');
		}

		// Process sub directories
		$directories = $this->getDirectories($path);
		foreach ($directories as $directoryPath)
		{
			$this->processDirectory($directoryPath);
		}
	}

	/**
	 * Move PDF files inside a PDF folder to the remote filesystem
	 *
	 * @param  string $path
	 */
	private function movePdfFilesToRemote($path)
	{
		$contents = $this->localFilesystem->listWith(['mimetype', 'path'], $path);

		foreach ($contents as $content)
		{
			if ($this->contentIsPdf($content))
			{
				$this->manager->put(
					'remote://'.$this->normalizeRemotePath($content['path']),
					$this->manager->read('local://'.$content['path'])
				);
			}
		}
	}

	/**
	 * Tell if a file is a PDF
	 *
	 * @param  array $content
	 * @return boolean
	 */
	private function contentIsPdf($content)
	{
		return $this->mimeIsPdf($content['mimetype']);
	}

	/**
	 * Get the local full path from a relative path
	 *
	 * @param  string $path
	 * @return string
	 */
	private function getFullPath($path)
	{
		return $this->localRootPath.DIRECTORY_SEPARATOR.$path;
	}

	/**
	 * Call the makefile for a given local relative path
	 *
	 * @param  string $path
	 */
	private function callMakefile($path)
	{
		$fullPath = $this->getFullPath($path);

		shell_exec("cd ".$fullPath."; make");
	}

	/**
	 * Determine from a MIME type if we have a PDF
	 *
	 * @param  array $content
	 * @return boolean
	 */
	private function mimeIsPdf($mime)
	{
		return $mime === 'application/pdf';
	}

	/**
	 * Get directories in the local filesystem from a path
	 *
	 * @param  string $path
	 * @return array An array of paths. Paths are relative.
	 */
	private function getDirectories($path)
	{
		if ($path == '.')
			$path = '';

		$directories = [];
		$contents = $this->manager->listContents('local://'.$path);

		foreach ($contents as $content)
		{
			// Remember the path if we've found a directory
			if ($content['type'] == 'dir')
				$directories[] = $content['path'];
		}

		return $directories;
	}

	/**
	 * Normalize the path before writing to the remote filesystem.
	 * We don't want to store PDFs on the remote filesystem inside a "pdf"
	 * directory.
	 *
	 * @param  string $path
	 * @return string
	 */
	private function normalizeRemotePath($path)
	{
		$infos = pathinfo($path);
		$path = str_replace('pdf', '', $infos['dirname']);

		return $path.$infos['basename'];
	}

	/**
	 * Tell if we have a makefile in the given path
	 *
	 * @param  string  $path
	 * @return boolean
	 */
	private function hasMakefile($path)
	{
		if ($path === '.')
			return $this->manager->has('local://makefile');

		return $this->manager->has('local://'.$path.'/makefile');
	}

	/**
	 * Tell if we have a "pdf" folder in the given path
	 *
	 * @param  string  $path
	 * @return boolean
	 */
	private function hasPdfFolder($path)
	{
		if ($path === '.')
			return $this->manager->has('local://pdf');

		return $this->manager->has('local://'.$path.'/pdf');
	}
}