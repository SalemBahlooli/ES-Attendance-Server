const { Pool, types } = require('pg');

// Device times are local (no timezone). Keep them as plain text "YYYY-MM-DD HH:MM:SS"
// so they are never shifted by the server's timezone.
types.setTypeParser(1114, (v) => v);

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
    ALTER TABLE attendance ADD COLUMN IF NOT EXISTS source TEXT DEFAULT 'adms';
    CREATE INDEX IF NOT EXISTS attendance_pin_time_idx ON attendance (pin, punch_time);

    ALTER TABLE devices ADD COLUMN IF NOT EXISTS lan_ip TEXT;
    ALTER TABLE devices ADD COLUMN IF NOT EXISTS lan_port INT;

    CREATE TABLE IF NOT EXISTS imports (
      id BIGSERIAL PRIMARY KEY,
      source TEXT NOT NULL,
      label TEXT,
      sn TEXT,
      total INT,
      inserted INT,
      duplicates INT,
      names INT,
      created_at TIMESTAMPTZ DEFAULT now()
    );

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
  `);
}

module.exports = { pool, init };
