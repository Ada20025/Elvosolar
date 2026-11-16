-- demo.sql — VOLITEĽNÉ testovacie dáta (nie je súčasťou nasadenia)
--
-- Predvolene appka žiadny demo účet nezakladá a demo123 sa nikdy neprihlási.
-- Tento súbor spusti len vtedy, keď na testovanie potrebuješ účet a zariadenia.
--
--   Účet:  demo@elvosolar.sk / demo123
--   Rola:  user  (NIE admin — admin práva si nechaj len pre seba)
--
-- Zmazanie po testovaní:
--   DELETE d FROM devices d JOIN users u ON u.id = d.user_id WHERE u.email = 'demo@elvosolar.sk';
--   DELETE FROM users WHERE email = 'demo@elvosolar.sk';

INSERT IGNORE INTO users (username, email, password_hash, role, email_verified)
VALUES ('Demo ElvoSolar', 'demo@elvosolar.sk',
        '$2y$10$Bhc9wn9NiooWrJprXwOmEOj5hJ48EJGgFPs1iLWTvysS1h0Met5WK', 'user', 1);

-- Ak bol účet zablokovaný predošlým vypnutím dema, tento skript ho odblokuje:
UPDATE users SET role = 'user' WHERE email = 'demo@elvosolar.sk' AND role = 'blocked';

INSERT INTO devices (user_id, name, serial_number, sub_type, status, connection_type,
                     smartlogger_ip, smartlogger_port, modbus_slave_id, battery_soc,
                     power_ac, temp, active_model_id, meter_control_mode)
SELECT u.id, 'ElvoControll Demo', 'DEMO-CM5-001', 'smartlogger', 'offline', 'modbus_tcp',
       '192.168.0.10', 502, 205, 0, 0, 25, 'AI', 'SMART'
FROM users u
WHERE u.email = 'demo@elvosolar.sk'
  AND NOT EXISTS (SELECT 1 FROM devices d WHERE d.serial_number = 'DEMO-CM5-001');

INSERT INTO devices (user_id, name, serial_number, sub_type, status, connection_type,
                     smartlogger_ip, smartlogger_port, modbus_slave_id, battery_soc,
                     power_ac, temp, active_model_id, meter_control_mode)
SELECT u.id, 'Demo Striedač (Huawei)', 'DEMO-CM5-002', 'hybrid', 'offline', 'modbus_rtu',
       '', 502, 1, 50, 0, 30, 'AI', 'SMART'
FROM users u
WHERE u.email = 'demo@elvosolar.sk'
  AND NOT EXISTS (SELECT 1 FROM devices d WHERE d.serial_number = 'DEMO-CM5-002');
