const { Pool, types } = require('pg');

// Device times are local (no timezone). Keep them as plain text "YYYY-MM-DD HH:MM:SS"
// so they are never shifted by the server's timezone.
types.setTypeParser(1114, (v) => v);
// DATE columns stay as "YYYY-MM-DD" too (pg would otherwise turn them into a Date at local midnight).
types.setTypeParser(1082, (v) => v);

const connectionString = process.env.DATABASE_URL;
if (!connectionString) {
  console.error('DATABASE_URL is not set. Add a PostgreSQL database in Railway and link it to this service.');
  process.exit(1);
}

const pool = new Pool({
  connectionString,
  ssl: /sslmode=require/.test(connectionString) ? { rejectUnauthorized: false } : false,
});

async function init() {
  await pool.query(`
    CREATE TABLE IF NOT EXISTS devices (
      sn TEXT PRIMARY KEY,
      name TEXT,
      ip TEXT,
      info TEXT,
      first_seen TIMESTAMPTZ DEFAULT now(),
      last_seen TIMESTAMPTZ DEFAULT now()
    );

    CREATE TABLE IF NOT EXISTS employees (
      pin TEXT PRIMARY KEY,
      name TEXT,
      created_at TIMESTAMPTZ DEFAULT now()
    );

    CREATE TABLE IF NOT EXISTS attendance (
      id BIGSERIAL PRIMARY KEY,
      sn TEXT NOT NULL,
      pin TEXT NOT NULL,
      punch_time TIMESTAMP NOT NULL,
      status INT,
      verify INT,
      raw TEXT,
      received_at TIMESTAMPTZ DEFAULT now(),
      UNIQUE (sn, pin, punch_time)
    );
    CREATE INDEX IF NOT EXISTS attendance_time_idx ON attendance (punch_time);
    CREATE INDEX IF NOT EXISTS attendance_pin_idx ON attendance (pin);

    CREATE TABLE IF NOT EXISTS commands (
      id BIGSERIAL PRIMARY KEY,
      sn TEXT NOT NULL,
      command TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'pending',
      result TEXT,
      created_at TIMESTAMPTZ DEFAULT now(),
      sent_at TIMESTAMPTZ,
      done_at TIMESTAMPTZ
    );

    -- Work schedules. weekend holds day numbers (0 = Sunday ... 6 = Saturday).
    CREATE TABLE IF NOT EXISTS shifts (
      id SERIAL PRIMARY KEY,
      name TEXT NOT NULL,
      start_time TIME NOT NULL,
      end_time TIME NOT NULL,
      grace_in INT NOT NULL DEFAULT 15,
      grace_out INT NOT NULL DEFAULT 0,
      weekend INT[] NOT NULL DEFAULT '{5,6}',
      is_default BOOLEAN NOT NULL DEFAULT false
    );

    CREATE TABLE IF NOT EXISTS departments (
      id SERIAL PRIMARY KEY,
      name TEXT NOT NULL,
      shift_id INT REFERENCES shifts(id) ON DELETE SET NULL
    );

    ALTER TABLE employees ADD COLUMN IF NOT EXISTS department_id INT REFERENCES departments(id) ON DELETE SET NULL;
    ALTER TABLE employees ADD COLUMN IF NOT EXISTS shift_id INT REFERENCES shifts(id) ON DELETE SET NULL;
    ALTER TABLE employees ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT true;
    ALTER TABLE employees ADD COLUMN IF NOT EXISTS start_date DATE;

    CREATE TABLE IF NOT EXISTS holidays (
      id SERIAL PRIMARY KEY,
      name TEXT NOT NULL,
      start_date DATE NOT NULL,
      end_date DATE NOT NULL
    );

    CREATE TABLE IF NOT EXISTS leaves (
      id SERIAL PRIMARY KEY,
      pin TEXT NOT NULL REFERENCES employees(pin) ON DELETE CASCADE,
      type TEXT NOT NULL,
      start_date DATE NOT NULL,
      end_date DATE NOT NULL,
      note TEXT,
      created_at TIMESTAMPTZ DEFAULT now()
    );
    CREATE INDEX IF NOT EXISTS leaves_dates_idx ON leaves (start_date, end_date);

    -- Dashboard accounts. permissions holds keys from PERMISSIONS in auth.js.
    CREATE TABLE IF NOT EXISTS roles (
      id SERIAL PRIMARY KEY,
      name TEXT NOT NULL,
      permissions TEXT[] NOT NULL DEFAULT '{}',
      is_system BOOLEAN NOT NULL DEFAULT false
    );

    CREATE TABLE IF NOT EXISTS users (
      id SERIAL PRIMARY KEY,
      username TEXT NOT NULL,
      name TEXT,
      password_hash TEXT NOT NULL,
      role_id INT REFERENCES roles(id),
      active BOOLEAN NOT NULL DEFAULT true,
      token_version INT NOT NULL DEFAULT 0,
      last_login TIMESTAMPTZ,
      created_at TIMESTAMPTZ DEFAULT now()
    );
    CREATE UNIQUE INDEX IF NOT EXISTS users_username_idx ON users (lower(username));

    CREATE TABLE IF NOT EXISTS audit_log (
      id BIGSERIAL PRIMARY KEY,
      user_id INT,
      username TEXT,
      action TEXT NOT NULL,
      details TEXT,
      ip TEXT,
      created_at TIMESTAMPTZ DEFAULT now()
    );
    CREATE INDEX IF NOT EXISTS audit_log_time_idx ON audit_log (created_at DESC);
  `);

  // First run: create the default shift from WORK_START / WORK_END.
  await pool.query(
    `INSERT INTO shifts (name, start_time, end_time, is_default)
     SELECT 'الدوام الرسمي', $1, $2, true WHERE NOT EXISTS (SELECT 1 FROM shifts)`,
    [process.env.WORK_START || '08:00', process.env.WORK_END || '16:00']
  );
}

module.exports = { pool, init };
