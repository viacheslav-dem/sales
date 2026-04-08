<?php
define('DB_PATH', __DIR__ . '/data/sales.db');
define('APP_NAME', 'Учёт продаж');

// Время — UTC+3 (Москва/Минск, без перехода на летнее).
// Все date()/time() в приложении и SQLite (через PHP) будут в этой зоне.
date_default_timezone_set('Europe/Minsk');
