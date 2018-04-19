@api
Feature: tokenAuth

	Background:
		Given using API version "1"
		And these users have been created:
			| username    | password | displayname  | email                 |
			| user1       | 1234     | User One     | u1@oc.com.np          |
		And token auth has been enforced

	Scenario: creating a user with basic auth should be blocked when token auth is enforced
		Given user "brand-new-user" has been deleted
		When the administrator sends a user creation request for user "brand-new-user" password "456firstpwd" using the API
		Then the OCS status code should be "997"
		And the HTTP status code should be "401"

	Scenario: moving a file should be blocked when token auth is enforced
		Given using new DAV path
		When user "user1" moves file "/textfile0.txt" to "/renamed_textfile0.txt" using the API
		Then the HTTP status code should be "401"

	Scenario: can access files app with an app password when token auth is enforced
		Given a new browser session for "user1" has been started
		And the user has generated a new app password named "my-client"
		When the user requests "/index.php/apps/files" with "GET" using the generated app password
		Then the HTTP status code should be "200"

	Scenario: cannot access files app with basic auth when token auth is enforced
		When user "user1" requests "/index.php/apps/files" with "GET" using basic auth
		Then the HTTP status code should be "401"

	Scenario: using WebDAV with basic auth should be blocked when token auth is enforced
		When user "user0" requests "/remote.php/webdav" with "PROPFIND" using basic auth
		Then the HTTP status code should be "401"

	Scenario: using OCS with basic auth should be blocked when token auth is enforced
		When user "user0" requests "/ocs/v1.php/apps/files_sharing/api/v1/remote_shares" with "GET" using basic auth
		Then the OCS status code should be "997"
		And the HTTP status code should be "401"
