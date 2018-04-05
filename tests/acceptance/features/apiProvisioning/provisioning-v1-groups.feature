@api
Feature: provisioning groups
	Background:
		Given using API version "1"

	Scenario Outline: Create a group
		Given group "<group-name>" has been deleted
		When the administrator sends a group creation request for group "<group-name>" using the API
		Then the OCS status code should be "100"
		And the HTTP status code should be "200"
		And group "<group-name>" should exist
		Examples:
			| group-name          | comment                                 |
			| new-group           | dash                                    |
			| the.group           | dot                                     |
			| España              | special European characters             |
			| नेपाली              | Unicode group name                      |
			| 0                   | The "false" group                       |
			| Finance (NP)        | Space and brackets                      |
			| Admin&Finance       | Ampersand                               |
			| admin:Pokhara@Nepal | Colon and @                             |
			| maintenance#123     | Hash sign                               |
			| maint+eng           | Plus sign                               |
			| $x<=>[y*z^2]!       | Maths symbols                           |
			| Mgmt\Middle         | Backslash                               |
			| Mgmt/Sydney         | Slash (special escaping happens)        |
			| Mgmt//NSW/Sydney    | Multiple slash                          |
			| 50%pass             | Percent sign (special escaping happens) |
			| 50%25=0             | %25 literal looks like an escaped "%"   |
			| 50%2Eagle           | %2E literal looks like an escaped "."   |
			| 50%2Fix             | %2F literal looks like an escaped slash |
			| staff?group         | Question mark                           |

	Scenario: getting an empty group
		Given group "new-group" has been created
		When user "admin" sends HTTP method "GET" to API endpoint "/cloud/groups/new-group"
		Then the OCS status code should be "100"
		And the HTTP status code should be "200"

	Scenario: getting users in a group
		Given user "brand-new-user" has been created
		And user "123" has been created
		And group "new-group" has been created
		And user "brand-new-user" has been added to group "new-group"
		And user "123" has been added to group "new-group"
		When user "admin" sends HTTP method "GET" to API endpoint "/cloud/groups/new-group"
		Then the OCS status code should be "100"
		And the HTTP status code should be "200"
		And the users returned by the API should be
			| brand-new-user |
			| 123            |

	Scenario: Getting all groups
		Given group "0" has been created
		And group "new-group" has been created
		And group "admin" has been created
		And group "España" has been created
		When user "admin" sends HTTP method "GET" to API endpoint "/cloud/groups"
		Then the groups returned by the API should be
			| España    |
			| admin     |
			| new-group |
			| 0         |

	Scenario Outline: Delete a group
		Given group "<group-name>" has been created
		When the administrator deletes group "<group-name>" using the API
		Then the OCS status code should be "100"
		And the HTTP status code should be "200"
		And group "<group-name>" should not exist
		Examples:
			| group-name          | comment                                 |
			| new-group           | dash                                    |
			| the.group           | dot                                     |
			| España              | special European characters             |
			| नेपाली              | Unicode group name                      |
			| 0                   | The "false" group                       |
			| Finance (NP)        | Space and brackets                      |
			| Admin&Finance       | Ampersand                               |
			| admin:Pokhara@Nepal | Colon and @                             |
			| maintenance#123     | Hash sign                               |
			| maint+eng           | Plus sign                               |
			| $x<=>[y*z^2]!       | Maths symbols                           |
			| Mgmt\Middle         | Backslash                               |
			| Mgmt/Sydney         | Slash (special escaping happens)        |
			| Mgmt//NSW/Sydney    | Multiple slash                          |
			| 50%pass             | Percent sign (special escaping happens) |
			| 50%25=0             | %25 literal looks like an escaped "%"   |
			| 50%2Eagle           | %2E literal looks like an escaped "."   |
			| 50%2Fix             | %2F literal looks like an escaped slash |
			| staff?group         | Question mark                           |
