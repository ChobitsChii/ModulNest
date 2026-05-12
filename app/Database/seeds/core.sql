-- Optionaler Standard-Seed für die Registrierung (attachRoleByName('user')).
INSERT IGNORE INTO roles (name) VALUES ('user');
INSERT IGNORE INTO roles (name) VALUES ('admin');
INSERT IGNORE INTO app_settings (`key`, `value`) VALUES ('public_registration_enabled', '1');

-- Fachmodule werden nicht im Core-Schema hart verdrahtet.
-- Native Module werden bei Bedarf in der Modulverwaltung per Auto-Discovery aus app/Modules/* initial angelegt.
-- Legacy-Module können weiterhin manuell in der Modulverwaltung registriert werden.
