<?php
/**
 * @author Viktar Dubiniuk <dubiniuk@owncloud.com>
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


namespace OCP\Util;

/**
 * This class provides non-static wrappers for the static OC class members
 *
 * @since 10.0.9
 */
class EnvironmentHelper {

	/**
	 * Get the ownCloud root path for http requests (e.g. owncloud/)
	 *
	 * @return string
	 * @since 10.0.9
	 */
	public function getWebRoot() {
		return \OC::$WEBROOT;
	}

	/**
	 * Get the installation path for owncloud on the server
	 * (e.g. /srv/http/owncloud)
	 *
	 * @return string
	 * @since 10.0.9
	 */
	public function getServerRoot() {
		return \OC::$SERVERROOT;
	}

	/**
	 * Get the apps folders location on the server as an array of
	 * arrays with 'path' and 'url' keys
	 * where 'path' keys holds an absolute filesystem path to the folder
	 * and 'url' key holds a web path relative to the ownCloud webroot
	 *
	 * @return string[][]
	 * @since 10.0.9
	 */
	public function getAppsRoots() {
		return \OC::$APPSROOTS;
	}

}
