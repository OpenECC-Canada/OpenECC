-- Command Bridge is now OpenECC. If the application name shown in the menu and page titles was saved as the old
-- default, change it to the new name. A name the organization chose itself is left alone.
-- No structure change. Safe to run more than once.
UPDATE app_settings
   SET setting_value = 'OpenECC'
 WHERE setting_key = 'branding_application_name'
   AND setting_value = 'Command Bridge';
