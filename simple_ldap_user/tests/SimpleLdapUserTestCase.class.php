<?php
/**
 * @file
 * SimpleLdapUserTestCase class.
 */

abstract class SimpleLdapUserTestCase extends SimpleLdapServerTestCase {

  protected $backdropUser;
  protected $ldapUser;
  protected $userPassword;

  /**
   * Inherited parent::setUp().
   */
  public function setUp() {
    // Get the live simple_ldap_user configuration.
    $config = config('simple_ldap_user.settings');
    $basedn = simple_ldap_user_variable_get('simple_ldap_user_basedn');
    $scope = simple_ldap_user_variable_get('simple_ldap_user_scope');
    $objectclass = simple_ldap_user_variable_get('simple_ldap_user_objectclass');
    $attribute_name = simple_ldap_user_variable_get('simple_ldap_user_attribute_name');
    $attribute_mail = simple_ldap_user_variable_get('simple_ldap_user_attribute_mail');
    $attribute_pass = simple_ldap_user_variable_get('simple_ldap_user_attribute_pass');
    $attribute_rdn = simple_ldap_user_variable_get('simple_ldap_user_attribute_rdn');
    $attribute_map = simple_ldap_user_variable_get('simple_ldap_user_attribute_map');
    $password_hash = simple_ldap_user_variable_get('simple_ldap_user_password_hash');
    $filter = simple_ldap_user_variable_get('simple_ldap_user_filter');
    $source = simple_ldap_user_variable_get('simple_ldap_user_source');
    $sync = simple_ldap_user_variable_get('simple_ldap_user_sync'); 
    $delete_ldap_user = simple_ldap_user_variable_get('simple_ldap_user_delete_from_ldap');

    // Make sure the variables from settings.php are written to the database.
    // Otherwise subsequent tests will not be able to read them. These are
    // cleaned up in $this->tearDown().
    config_set('simple_ldap_user.settings', 'simple_ldap_user_attribute_map', $attribute_map);

    // Create the simpletest sandbox.
    $modules = func_get_args();
    if (isset($modules[0]) && is_array($modules[0])) {
      $modules = $modules[0];
    }
    parent::setUp($modules);

    // Initialize an LDAP server object.
    $server = SimpleLdapServer::singleton();

    // Create a set of Backdrop-only users before enabling Simple LDAP User so
    // that they are only present in Backdrop.
    for ($i = 0; $i < 5; $i++) {
      $this->backdropUser[$i] = $this->backdropCreateUser();
    }

    // Get a list of required attributes for the configured objectclass(es).
    $must = array();
    foreach ($objectclass as $o) {
      foreach ($server->schema->must($o, TRUE) as $m) {
        if (!in_array($m, $must)) {
          $must[] = strtolower($m);
        }
      }
    }

    // Add mapped fields to user entity.
    foreach ($attribute_map as $attribute) {
      // Clean up the attribute. We can't use simple_ldap_user_variable_get()
      // yet because simple_ldap_user is not enabled yet. The module can't be
      // enabled until after the Backdrop users are initialized, or they would
      // sync to LDAP, and invalidate the tests. Chicken and egg problem here.
      $attribute['ldap'] = strtolower($attribute['ldap']);
      if (!is_array($attribute['backdrop'])) {
        $attribute['backdrop'] = array($attribute['backdrop']);
      }

      // Skip one-to-many mappings.
      if (count($attribute['backdrop']) > 1) {
        continue;
      }

      // Get the Backdrop name and type.
      $backdrop_attribute = reset($attribute['backdrop']);
      $type = substr($backdrop_attribute, 0, 1);

      // Create user fields.
      if ($type == '#') {
        $backdrop_attribute = substr($backdrop_attribute, 1);
        $required = in_array($attribute['ldap'], $must);

        $attributetype = $server->schema->get('attributetypes', $attribute['ldap']);
        // A syntax type of 1.3.6.1.4.1.1466.115.121.1.27 is an integer.
        if (isset($attributetype['syntax']) && $attributetype['syntax'] == '1.3.6.1.4.1.1466.115.121.1.27') {
          $type = 'number_integer';
        }
        else {
          $type = 'text';
        }

        field_create_field(array(
          'field_name' => $backdrop_attribute,
          'type' => $type,
          'cardinality' => 1,
        ));
        field_create_instance(array(
          'field_name' => $backdrop_attribute,
          'entity_type' => 'user',
          'label' => $backdrop_attribute,
          'bundle' => 'user',
          'required' => $required,
          'settings' => array(
            'user_register_form' => $required,
          ),
        ));

        // Populate the field value of each of the test Backdrop users.
        foreach ($this->backdropUser as $backdrop_user) {
          if ($type == 'number_integer') {
            $edit[$backdrop_attribute]['und'][0]['value'] = $backdrop_user->uid + 1000;
            $backdrop_user->{$backdrop_attribute} = $edit[$backdrop_attribute];
          }
          else {
            $edit[$backdrop_attribute]['und'][0]['value'] = $this->randomName();
            $backdrop_user->{$backdrop_attribute} = $edit[$backdrop_attribute];
          }
          $backdrop_user = user_save($backdrop_user);
        }
      }
    }

    // Enable the Simple LDAP User module.
    $modules = array('simple_ldap_user');
    $success = module_enable($modules);
    $this->assertTrue($success, t('Enabled modules: %modules', array('%modules' => implode(', ', $modules))));

    // Configure the sandboxed simple_ldap_user.
    $config->set('simple_ldap_user_basedn', $basedn);
    $config->set('simple_ldap_user_scope', $scope);
    $config->set('simple_ldap_user_objectclass', $objectclass);
    $config->set('simple_ldap_user_attribute_name', $attribute_name);
    $config->set('simple_ldap_user_attribute_mail', $attribute_mail);
    $config->set('simple_ldap_user_attribute_pass', $attribute_pass);
    $config->set('simple_ldap_user_attribute_rdn', $attribute_rdn);
    $config->set('simple_ldap_user_attribute_map', $attribute_map);
    $config->set('simple_ldap_user_password_hash', $password_hash);
    $config->set('simple_ldap_user_filter', $filter);
    $config->set('simple_ldap_user_source', $source);
    $config->set('simple_ldap_user_sync', $sync);
    $config->set('simple_ldap_user_delete_from_ldap', $delete_ldap_user);
    $config->save();

    // Get the fully qualified attribute map.
    $mapObject = SimpleLdapUserMap::singleton();

    // Create a set of LDAP entries to use during testing.
    for ($i = 0; $i < 5; $i++) {
      // Create a new LDAP User object, and set the attributes.
      $name = $this->randomName();
      $this->ldapUser[$i] = new SimpleLdapUser($name);
      $this->ldapUser[$i]->sn = 'UserSurname';
      $this->ldapUser[$i]->$attribute_mail = $this->randomName() . '@example.com';
      foreach ($mapObject->map as $attribute) {
        $attributetype = $server->schema->get('attributetypes', $attribute['ldap']);
        // A syntax type of 1.3.6.1.4.1.1466.115.121.1.27 is an integer.
        if (isset($attributetype['syntax']) && $attributetype['syntax'] == '1.3.6.1.4.1.1466.115.121.1.27') {
          $this->ldapUser[$i]->{$attribute['ldap']} = 1000 + $i;
        }
        else {
          $this->ldapUser[$i]->{$attribute['ldap']} = $this->randomName();
        }
      }

      // Set the DN.
      if (empty($attribute_rdn)) {
        $this->ldapUser[$i]->dn = $attribute_name . '=' . $name . ',' . $basedn;
      }
      else {
        $this->ldapUser[$i]->dn = $attribute_rdn . '=' . $this->ldapUser[$i]->{$attribute_rdn}[0] . ',' . $basedn;
      }

      // Set the user's password.
      $this->userPassword[$i] = $this->randomName();
      SimpleLdapUser::hash($this->userPassword[$i], $this->userPassword[$i]);
      $this->ldapUser[$i]->$attribute_pass = $this->userPassword[$i];

      // Save the entry to LDAP.
      $this->ldapUser[$i]->save();

      // If no exception is thrown by save() then the save was successful, but
      // show the DN to make the tester feel better.
      $this->assertTrue($this->ldapUser[$i]->exists, t(':dn was added to LDAP', array(':dn' => $this->ldapUser[$i]->dn)));

      // Verify that the LDAP user can bind.
      $result = $server->bind($this->ldapUser[$i]->dn, $this->userPassword[$i]);
      $this->assertTrue($result, t('Successful bind with :dn using password :pw', array(':dn' => $this->ldapUser[$i]->dn, ':pw' => $this->userPassword[$i])));
    }

  }

  /**
   * Inherited parent::tearDown().
   */
  protected function tearDown() {
    // Get module configuration.
    $attribute_name = simple_ldap_user_variable_get('simple_ldap_user_attribute_name');

    // Clean up variables written to the database in setUp().
    config_clear('simple_ldap_user.settings', 'simple_ldap_user_attribute_map');

    // Clean up the LDAP entries that were added for testing.
    foreach ($this->ldapUser as $lu) {
      // Create a new SimpleLdapUser object, which performs a new search based
      // on the name attribute. This accounts for the possibility that the LDAP
      // entry has changed since it was created.
      $ldap_user = new SimpleLdapUser($lu->{$attribute_name}[0]);
      $ldap_user->delete();
      $this->assertFalse($ldap_user->exists, t(':dn was removed from LDAP', array(':dn' => $ldap_user->dn)));
    }

    parent::tearDown();
  }

  /**
   * Verify that a user is unable to log in.
   */
  public function backdropNoLogin(stdClass $user) {
    if ($this->loggedInUser) {
      $this->backdropLogout();
    }

    $edit = array(
      'name' => $user->name,
      'pass' => $user->pass_raw,
    );
    $this->backdropPost('user', $edit, t('Log in'));

    // Verify that the user was unable to log in.
    $pass = $this->assertNoLink(t('Log out'), 0, t('User %name unable to log in.', array('%name' => $user->name)), t('User login'));

    if (!$pass) {
      $this->loggedInUser = $user;
    }
  }

  /**
   * Log in with User 1.
   */
  public function backdropUser1Login() {
    if ($this->loggedInUser) {
      $this->backdropLogout();
    }

    // Load password hashing API.
    if (!function_exists('user_hash_password')) {
      require_once BACKDROP_ROOT . '/' . settings_get('password_inc', 'core/includes/password.inc');
    }

    // Set user1's password to something random in the database.
    $pass = hash('sha256', microtime());
    db_query("UPDATE {users} SET pass = :hash WHERE uid = 1", array(':hash' => user_hash_password($pass)));
    backdrop_flush_all_caches();

    // Log in as user1.
    $admin_user = user_load(1);
    $admin_user->pass_raw = $pass;
    $this->backdropLogin($admin_user);
  }

  /**
   * Verifies that Backdrop, LDAP, and the test user values match.
   */
  public function verifySync($suffix = '', $name = NULL, $multi = FALSE) {
    // Load configuration variables.
    $attribute_name = simple_ldap_user_variable_get('simple_ldap_user_attribute_name');
    $attribute_mail = simple_ldap_user_variable_get('simple_ldap_user_attribute_mail');

    $server = SimpleLdapServer::singleton();

    // Load the LDAP user.
    if ($name === NULL) {
      $ldap_user = new SimpleLdapUser($this->ldapUser[0]->{$attribute_name}[0]);
      $control = $this->ldapUser[0];
    }
    else {
      $ldap_user = new SimpleLdapUser($name);
      $control = $ldap_user;
    }

    // Load the Backdrop user.
    $backdrop_user = user_load_multiple(array(), array('name' => $ldap_user->{$attribute_name}[0]), TRUE);
    $backdrop_user = reset($backdrop_user);

    // Check the mapped fields.
    $mapObject = SimpleLdapUserMap::singleton();
    $attribute_map = $mapObject->map;
    array_unshift($attribute_map, array('backdrop' => array('mail'), 'ldap' => $attribute_mail));
    foreach ($attribute_map as $attribute) {

      // Skip Backdrop-to-ldap, one-to-many maps, unless explicitely requested.
      if (count($attribute['backdrop']) == 1 || $multi) {

        // Parse the Backdrop attribute name.
        $backdrop = '';
        foreach ($attribute['backdrop'] as $backdrop_attribute) {
          $type = substr($backdrop_attribute, 0, 1);
          switch ($type) {
            case '#':
              $backdrop_attribute = substr($backdrop_attribute, 1);
              $items = field_get_items('user', $backdrop_user, $backdrop_attribute);
              $backdrop .= ' ' . $items[0]['value'];
              break;

            default:
              $backdrop .= ' ' . $backdrop_user->$backdrop_attribute;
          }
        }

        $backdrop = trim($backdrop);

        // Make sure Backdrop and ldap match.
        $this->assertEqual($ldap_user->{$attribute['ldap']}[0], $backdrop, t('The @ldap LDAP attribute :ldap and the @backdrop Backdrop field :backdrop are synchronized.',
          array(
            '@ldap' => $attribute['ldap'],
            '@backdrop' => $backdrop_attribute,
            ':ldap' => $ldap_user->{$attribute['ldap']}[0],
            ':backdrop' => $backdrop,
          )
        ));

        // Only single-valued fields will have a control to check.
        if (count($attribute['backdrop']) == 1) {
          // Make sure simple entries match the control.
          $attributetype = $server->schema->get('attributetypes', $attribute['ldap']);
          // A syntax type of 1.3.6.1.4.1.1466.115.121.1.27 is an integer.
          if (isset($attributetype['syntax']) && $attributetype['syntax'] == '1.3.6.1.4.1.1466.115.121.1.27') {
            if ($suffix) {
              $value = hexdec(substr(sha1($suffix), 0, 2));
            }
            else {
              $value = $control->{$attribute['ldap']}[0];
            }
          }
          else {
            $value = $control->{$attribute['ldap']}[0] . $suffix;
          }
          $this->assertEqual($value, $backdrop, t(':value matches the control value :control',
            array(
              ':value' => $backdrop,
              ':control' => $value,
            )
          ));
        }
      }

    }
  }

}
