/*
 * Copyright (c) 2015
 *
 * This file is licensed under the Affero General Public License version 3
 * or later.
 *
 * See the COPYING-README file.
 *
 */

(function() {
	/**
	 * @memberof OCA.Versions
	 */
	var VersionCollection = OC.Backbone.Collection.extend({
		model: OCA.Versions.VersionModel,

		/**
		 * @var OCA.Files.FileInfoModel
		 */
		_fileInfo: null,

		_endReached: false,
		_currentIndex: 0,

		url: function() {
			var url = OC.generateUrl('/apps/files_versions/ajax/getVersions.php');
			var query = {
				source: this._fileInfo.getFullPath(),
				start: this._currentIndex
			};
			return url + '?' + OC.buildQueryString(query);
		},

		setFileInfo: function(fileInfo) {
			this._fileInfo = fileInfo;
			// reset
			this._endReached = false;
			this._currentIndex = 0;
		},

		getFileInfo: function() {
			return this._fileInfo;
		},

		hasMoreResults: function() {
			return !this._endReached;
		},

		fetch: function(options) {
			if (!options || options.remove) {
				this._currentIndex = 0;
			}
			return OC.Backbone.Collection.prototype.fetch.apply(this, arguments);
		},

		/**
		 * Fetch the next set of results
		 */
		fetchNext: function() {
			if (!this.hasMoreResults()) {
				return null;
			}
			if (this._currentIndex === 0) {
				return this.fetch();
			}
			return this.fetch({remove: false});
		},

		reset: function() {
			this._currentIndex = 0;
			OC.Backbone.Collection.prototype.reset.apply(this, arguments);
		},

		parse: function(result) {
			var fullPath = this._fileInfo.getFullPath();
			var fileId = this._fileInfo.get('id');
			return _.map(result, function(version) {
				var revision = version.id;
				return {
					id: revision,
					name: version.name,
					fullPath: fullPath,
					timestamp: moment(new Date(version['{DAV:}getlastmodified'])).format('X'),
					size: version['{DAV:}getcontentlength'],
					fileId: fileId
				};
			});
		}
	});

	OCA.Versions = OCA.Versions || {};

	OCA.Versions.VersionCollection = VersionCollection;
})();

