<?php
// LDAP Configuration - Last updated: 2025-11-13 02:38:06
define('ENABLE_LDAP_AUTH', true);
define('LDAP_HOST', '172.16.1.6');
define('LDAP_PORT', '389');
define('LDAP_BASE_DN', 'dc=sti,dc=com');
define('LDAP_DOMAIN', 'sti.com');
define('LDAP_LOGIN_ATTRIBUTE', 'sAMAccountName');
define('LDAP_USER_SEARCH_FILTER', '(&(objectClass=user)(objectCategory=person)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))');
define('LDAP_BIND_DN', 'cn=nagios,ou=admin,dc=sti,dc=com');
define('LDAP_BIND_PASSWORD', 'Reset123');
