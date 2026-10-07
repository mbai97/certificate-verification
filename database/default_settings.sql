INSERT INTO app_settings (setting_key, setting_value)
VALUES
    ('brand_color', '#1ca7d9'),
    ('accent_color', '#d7265b'),
    ('gold_color', '#d4af37'),
    ('signature_label', 'Head of Department')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
