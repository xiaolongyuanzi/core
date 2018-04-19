<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Files\ObjectStore;

use Icewind\Streams\IteratorDirectory;
use OC\Files\Cache\CacheEntry;
use OC\Files\Storage\Common;
use OC\Files\Stream\Close;
use OCP\Constants;
use OCP\Files\NotFoundException;
use OCP\Files\ObjectStore\IObjectStore;
use OCP\Files\ObjectStore\IVersionedObjectStorage;
use OCP\Util;

class ObjectStoreStorage extends Common {

	/**
	 * @var array
	 */
	private static $tmpFiles = [];
	/**
	 * @var IObjectStore $objectStore
	 */
	protected $objectStore;
	/**
	 * @var string $id
	 */
	protected $id;
	/**
	 * @var \OC\User\User $user
	 */
	protected $user;

	private $objectPrefix = 'urn:oid:';

	/**
	 * ObjectStoreStorage constructor.
	 *
	 * @param $params
	 * @throws \Exception
	 */
	public function __construct($params) {
		if (isset($params['objectstore']) && $params['objectstore'] instanceof IObjectStore) {
			$this->objectStore = $params['objectstore'];
		} else {
			throw new \Exception('missing IObjectStore instance');
		}
		if (isset($params['storageid'])) {
			$this->id = 'object::store:' . $params['storageid'];
		} else {
			$this->id = 'object::store:' . $this->objectStore->getStorageId();
		}
		if (isset($params['objectPrefix'])) {
			$this->objectPrefix = $params['objectPrefix'];
		}
		//initialize cache with root directory in cache
		if (!$this->is_dir('/')) {
			$this->mkdir('/');
		}
	}

	public function mkdir($path) {
		$path = $this->normalizePath($path);

		if ($this->file_exists($path)) {
			return false;
		}

		$mTime = time();
		$data = [
			'mimetype' => 'httpd/unix-directory',
			'size' => 0,
			'mtime' => $mTime,
			'storage_mtime' => $mTime,
			'permissions' => Constants::PERMISSION_ALL,
		];
		if ($path === '') {
			//create root on the fly
			$data['etag'] = $this->getETag('');
			$this->getCache()->put('', $data);
			return true;
		}

		// if parent does not exist, create it
		$parent = $this->normalizePath(dirname($path));
		$parentType = $this->filetype($parent);
		if ($parentType === false) {
			if (!$this->mkdir($parent)) {
				// something went wrong
				return false;
			}
		} else if ($parentType === 'file') {
			// parent is a file
			return false;
		}
		// finally create the new dir
		$mTime = time(); // update mtime
		$data['mtime'] = $mTime;
		$data['storage_mtime'] = $mTime;
		$data['etag'] = $this->getETag($path);
		$this->getCache()->put($path, $data);
		return true;
	}

	/**
	 * @param string $path
	 * @return string
	 */
	private function normalizePath($path) {
		$path = trim($path, '/');
		//FIXME why do we sometimes get a path like 'files//username'?
		$path = str_replace('//', '/', $path);

		// dirname('/folder') returns '.' but internally (in the cache) we store the root as ''
		if (!$path || $path === '.') {
			$path = '';
		}

		return $path;
	}

	/**
	 * Object Stores use a NoopScanner because metadata is directly stored in
	 * the file cache and cannot really scan the filesystem. The storage passed in is not used anywhere.
	 *
	 * @param string $path
	 * @param \OC\Files\Storage\Storage (optional) the storage to pass to the scanner
	 * @return \OC\Files\ObjectStore\NoopScanner
	 */
	public function getScanner($path = '', $storage = null) {
		if (!$storage) {
			$storage = $this;
		}
		if (!isset($this->scanner)) {
			$this->scanner = new NoopScanner($storage);
		}
		return $this->scanner;
	}

	public function getId() {
		return $this->id;
	}

	public function rmdir($path) {
		$path = $this->normalizePath($path);

		if (!$this->is_dir($path)) {
			return false;
		}

		$this->rmObjects($path);

		$this->getCache()->remove($path);

		return true;
	}

	private function rmObjects($path) {
		$children = $this->getCache()->getFolderContents($path);
		foreach ($children as $child) {
			if ($child['mimetype'] === 'httpd/unix-directory') {
				$this->rmObjects($child['path']);
			} else {
				$this->unlink($child['path']);
			}
		}
	}

	public function unlink($path) {
		$path = $this->normalizePath($path);
		$stat = $this->stat($path);

		if ($stat && isset($stat['fileid'])) {
			if ($stat['mimetype'] === 'httpd/unix-directory') {
				return $this->rmdir($path);
			}
			try {
				$this->objectStore->deleteObject($this->getURN($stat['fileid']));
			} catch (\Exception $ex) {
				if ($ex->getCode() !== 404) {
					Util::writeLog('objectstore', 'Could not delete object: ' . $ex->getMessage(), Util::ERROR);
					return false;
				} else {
					//removing from cache is ok as it does not exist in the objectstore anyway
				}
			}
			$this->getCache()->remove($path);
			return true;
		}
		return false;
	}

	public function stat($path) {
		$path = $this->normalizePath($path);
		$cacheEntry = $this->getCache()->get($path);
		if ($cacheEntry instanceof CacheEntry) {
			return $cacheEntry->getData();
		}

		return false;
	}

	/**
	 * Override this method if you need a different unique resource identifier for your object storage implementation.
	 * The default implementations just appends the fileId to 'urn:oid:'. Make sure the URN is unique over all users.
	 * You may need a mapping table to store your URN if it cannot be generated from the fileid.
	 *
	 * @param int $fileId the fileid
	 * @return null|string the unified resource name used to identify the object
	 */
	protected function getURN($fileId) {
		if (is_numeric($fileId)) {
			return $this->objectPrefix . $fileId;
		}
		return null;
	}

	public function opendir($path) {
		$path = $this->normalizePath($path);

		try {
			$files = [];
			$folderContents = $this->getCache()->getFolderContents($path);
			foreach ($folderContents as $file) {
				$files[] = $file['name'];
			}

			return IteratorDirectory::wrap($files);
		} catch (\Exception $e) {
			Util::writeLog('objectstore', $e->getMessage(), Util::ERROR);
			return false;
		}
	}

	public function filetype($path) {
		$path = $this->normalizePath($path);
		$stat = $this->stat($path);
		if ($stat) {
			if ($stat['mimetype'] === 'httpd/unix-directory') {
				return 'dir';
			}
			return 'file';
		}

		return false;
	}

	public function fopen($path, $mode) {
		$path = $this->normalizePath($path);

		switch ($mode) {
			case 'r':
			case 'rb':
				$stat = $this->stat($path);
				if (is_array($stat)) {
					try {
						return $this->objectStore->readObject($this->getURN($stat['fileid']));
					} catch (\Exception $ex) {
						Util::writeLog('objectstore', 'Could not get object: ' . $ex->getMessage(), Util::ERROR);
						return false;
					}
				} else {
					return false;
				}
			case 'w':
			case 'wb':
			case 'a':
			case 'ab':
			case 'r+':
			case 'w+':
			case 'wb+':
			case 'a+':
			case 'x':
			case 'x+':
			case 'c':
			case 'c+':
				if (strrpos($path, '.') !== false) {
					$ext = substr($path, strrpos($path, '.'));
				} else {
					$ext = '';
				}
				$tmpFile = \OC::$server->getTempManager()->getTemporaryFile($ext);
				Close::registerCallback($tmpFile, [$this, 'writeBack']);
				if ($this->file_exists($path)) {
					$source = $this->fopen($path, 'r');
					file_put_contents($tmpFile, $source);
				}
				self::$tmpFiles[$tmpFile] = $path;

				return fopen('close://' . $tmpFile, $mode);
		}
		return false;
	}

	public function file_exists($path) {
		$path = $this->normalizePath($path);
		return (bool)$this->stat($path);
	}

	public function rename($source, $target) {
		$source = $this->normalizePath($source);
		$target = $this->normalizePath($target);
		$this->remove($target);
		$this->getCache()->move($source, $target);
		$this->touch(dirname($target));
		return true;
	}

	public function moveFromStorage(\OCP\Files\Storage $sourceStorage, $sourceInternalPath, $targetInternalPath) {
		if ($sourceStorage === $this) {
			return $this->copy($sourceInternalPath, $targetInternalPath);
		}
		// cross storage moves need to perform a move operation
		// TODO: there is some cache updating missing which requires bigger changes and is
		//       subject to followup PRs
		if (!$sourceStorage->instanceOfStorage(self::class)) {
			return parent::moveFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
		}

		// source and target live on the same object store and we can simply rename
		// which updates the cache properly
		$this->getUpdater()->renameFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
		return true;
	}


	public function getMimeType($path) {
		$path = $this->normalizePath($path);
		$stat = $this->stat($path);
		if (is_array($stat)) {
			return $stat['mimetype'];
		}

		return false;
	}

	public function touch($path, $mtime = null) {
		if ($mtime === null) {
			$mtime = time();
		}

		$path = $this->normalizePath($path);
		$dirName = dirname($path);
		$parentExists = $this->is_dir($dirName);
		if (!$parentExists) {
			return false;
		}

		$stat = $this->stat($path);
		if (is_array($stat)) {
			// update existing mtime in db
			$stat['mtime'] = $mtime;
			$this->getCache()->update($stat['fileid'], $stat);
		} else {
			$mimeType = \OC::$server->getMimeTypeDetector()->detectPath($path);
			// create new file
			$stat = [
				'etag' => $this->getETag($path),
				'mimetype' => $mimeType,
				'size' => 0,
				'mtime' => $mtime,
				'storage_mtime' => $mtime,
				'permissions' => Constants::PERMISSION_ALL - Constants::PERMISSION_CREATE,
			];
			$stat['fileid'] = $this->getCache()->put($path, $stat);
			try {
				//read an empty file from memory
				$storageStats = $this->objectStore->writeObject($this->getURN($stat['fileid']), fopen('php://memory', 'r'));
				if (isset($storageStats['etag'])) {
					$stat['etag'] = $storageStats['etag'];
					$this->getCache()->update($stat['fileid'], $stat);
				}
			} catch (\Exception $ex) {
				$this->getCache()->remove($path);
				Util::writeLog('objectstore', 'Could not create object: ' . $ex->getMessage(), Util::ERROR);
				return false;
			}
		}
		return true;
	}

	/**
	 * @param $tmpFile
	 * @throws \Exception
	 */
	public function writeBack($tmpFile) {
		if (!isset(self::$tmpFiles[$tmpFile])) {
			return;
		}

		$path = self::$tmpFiles[$tmpFile];
		$stat = $this->stat($path);
		if (empty($stat)) {
			// create new file
			$stat = [
				'permissions' => Constants::PERMISSION_ALL - Constants::PERMISSION_CREATE,
			];
		}
		// update stat with new data
		$mTime = time();
		$stat['size'] = filesize($tmpFile);
		$stat['mtime'] = $mTime;
		$stat['storage_mtime'] = $mTime;
		$stat['mimetype'] = \OC::$server->getMimeTypeDetector()->detect($tmpFile);
		$stat['etag'] = $this->getETag($path);

		$stat['fileid'] = $this->getCache()->put($path, $stat);
		try {
			//upload to object storage
			$storageStats = $this->objectStore->writeObject($this->getURN($stat['fileid']), fopen($tmpFile, 'r'));
			if (isset($storageStats['etag'])) {
				$stat['etag'] = $storageStats['etag'];
				$this->getCache()->update($stat['fileid'], $stat);
			}
		} catch (\Exception $ex) {
			$this->getCache()->remove($path);
			Util::writeLog('objectstore', 'Could not create object: ' . $ex->getMessage(), Util::ERROR);
			throw $ex; // make this bubble up
		}
	}

	/**
	 * external changes are not supported, exclusive access to the object storage is assumed
	 *
	 * @param string $path
	 * @param int $time
	 * @return false
	 */
	public function hasUpdated($path, $time) {
		return false;
	}

	public function saveVersion($internalPath) {
		if ($this->objectStore instanceof IVersionedObjectStorage) {
			$stat = $this->stat($internalPath);
			// There are cases in the current implementation where saveVersion
			// is called before the file was even written.
			// There is nothing to be done in this case.
			// We return true to not trigger the fallback implementation
			if ($stat === false) {
				return true;
			}
			return $this->objectStore->saveVersion($this->getURN($stat['fileid']));
		}
		return parent::saveVersion($internalPath);
	}

	/**
	 * @inheritdoc
	 */
	public function getVersions($internalPath) {
		if ($this->objectStore instanceof IVersionedObjectStorage) {
			$stat = $this->stat($internalPath);
			if ($stat === false) {
				throw new NotFoundException();
			}
			return $this->objectStore->getVersions($this->getURN($stat['fileid']));
		}
		return parent::getVersions($internalPath);
	}

	/**
	 * @inheritdoc
	 */
	public function getVersion($internalPath, $versionId) {
		if ($this->objectStore instanceof IVersionedObjectStorage) {
			$stat = $this->stat($internalPath);
			if ($stat === false) {
				throw new NotFoundException();
			}
			return $this->objectStore->getVersion($this->getURN($stat['fileid']), $versionId);
		}
		return parent::getVersion($internalPath, $versionId);
	}

	/**
	 * @inheritdoc
	 */
	public function getContentOfVersion($internalPath, $versionId) {
		if ($this->objectStore instanceof IVersionedObjectStorage) {
			$stat = $this->stat($internalPath);
			if ($stat === false) {
				throw new NotFoundException();
			}
			return $this->objectStore->getContentOfVersion($this->getURN($stat['fileid']), $versionId);
		}
		return parent::getContentOfVersion($internalPath, $versionId);
	}

	/**
	 * @inheritdoc
	 */
	public function restoreVersion($internalPath, $versionId) {
		if ($this->objectStore instanceof IVersionedObjectStorage) {
			$stat = $this->stat($internalPath);
			if ($stat === false) {
				throw new NotFoundException();
			}
			return $this->objectStore->restoreVersion($this->getURN($stat['fileid']), $versionId);
		}
		return parent::restoreVersion($internalPath, $versionId);
	}

	/**
	 * @inheritdoc
	 */
	public function getDirectDownload($path, $versionId = null) {
		$path = $this->normalizePath($path);
		$stat = $this->stat($path);

		$url = $this->objectStore->getDirectDownload($this->getURN($stat['fileid']), $versionId, basename($path));
		if ($url === null) {
			return [];
		}

		return [
			'url' => $url
		];
	}

}
