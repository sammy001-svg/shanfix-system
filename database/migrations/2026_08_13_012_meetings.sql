-- =====================================================================
-- Migration 012 — meetings
--
-- Scheduling, a room people can actually meet in, minutes taken while the
-- discussion happens, and reminders before it starts.
--
-- Four tables:
--   meetings              the appointment itself, and its final minutes
--   meeting_participants  who is invited — staff and outside clients alike
--   meeting_notes         what was said, appended live by whoever is typing
--   meeting_signals       the plumbing that lets one browser show another
--                         its screen; see the comment on that table
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS meetings (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(200) NOT NULL,
  agenda         TEXT DEFAULT NULL,

  -- The room address. Long and random because anyone holding it can join
  -- an internal discussion; it is the only credential an outside guest has.
  public_token   CHAR(48) NOT NULL,

  scheduled_at   DATETIME NOT NULL,
  duration_mins  SMALLINT UNSIGNED NOT NULL DEFAULT 30,

  -- scheduled | in_progress | ended | cancelled
  status         VARCHAR(20) NOT NULL DEFAULT 'scheduled',
  started_at     DATETIME DEFAULT NULL,
  ended_at       DATETIME DEFAULT NULL,

  host_id        INT UNSIGNED DEFAULT NULL,
  -- Set when the meeting is with a client on the books, so it shows on
  -- their profile alongside everything else.
  client_id      INT UNSIGNED DEFAULT NULL,

  -- Whether the shared link lets people in without an account at all.
  allow_guests   TINYINT(1) NOT NULL DEFAULT 1,

  -- Minutes before the start to remind people, e.g. "60,30". Blank uses
  -- the system default.
  reminder_mins  VARCHAR(60) NOT NULL DEFAULT '',

  -- The written record, agreed after the fact. The running commentary
  -- lives in meeting_notes; this is what someone tidies up and keeps.
  minutes            MEDIUMTEXT DEFAULT NULL,
  minutes_updated_at DATETIME DEFAULT NULL,
  minutes_updated_by INT UNSIGNED DEFAULT NULL,

  created_by     INT UNSIGNED DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_meeting_token (public_token),
  KEY idx_meeting_when (status, scheduled_at),
  KEY idx_meeting_client (client_id),

  CONSTRAINT fk_meeting_host   FOREIGN KEY (host_id)   REFERENCES users(id)   ON DELETE SET NULL,
  CONSTRAINT fk_meeting_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Who is invited.
--
-- One table for colleagues and outside guests: a meeting does not care
-- which is which, and keeping them apart would mean every query and every
-- reminder written twice. user_id is set for staff and null for guests,
-- whose name, email and phone are recorded directly.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meeting_participants (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id   INT UNSIGNED NOT NULL,

  user_id      INT UNSIGNED DEFAULT NULL,
  name         VARCHAR(160) NOT NULL,
  email        VARCHAR(190) DEFAULT NULL,
  phone        VARCHAR(30)  DEFAULT NULL,

  -- host | required | optional
  invite_role  VARCHAR(20) NOT NULL DEFAULT 'required',
  -- invited | accepted | declined
  response     VARCHAR(20) NOT NULL DEFAULT 'invited',

  joined_at    DATETIME DEFAULT NULL,
  left_at      DATETIME DEFAULT NULL,

  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- A colleague appears once per meeting. Guests are distinguished by
  -- email, so the same address cannot be invited twice either.
  UNIQUE KEY uq_participant_user  (meeting_id, user_id),
  UNIQUE KEY uq_participant_email (meeting_id, email),
  KEY idx_participant_meeting (meeting_id),

  CONSTRAINT fk_participant_meeting FOREIGN KEY (meeting_id)
    REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_participant_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- The running record, typed while the meeting happens.
--
-- Appended rather than edited: two people taking notes at once would
-- otherwise overwrite each other, and the order things were said in is
-- part of what makes minutes useful.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meeting_notes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED DEFAULT NULL,
  -- Whoever wrote it, including a guest who has no account.
  author_name VARCHAR(160) NOT NULL,
  body        VARCHAR(2000) NOT NULL,
  -- note | decision | action
  kind        VARCHAR(20) NOT NULL DEFAULT 'note',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_note_meeting (meeting_id, id),

  CONSTRAINT fk_note_meeting FOREIGN KEY (meeting_id)
    REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_note_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Screen sharing.
--
-- Browsers set up a direct connection to each other, but they cannot find
-- each other unaided: each has to pass the other a description of itself
-- and a list of network routes. That exchange is all this table is — a
-- postbox the two ends poll.
--
-- Ordinary hosting cannot hold a socket open, so the browsers ask for new
-- rows every second or so instead. Rows are consumed on read and swept
-- afterwards; nothing here is worth keeping once a meeting is over.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meeting_signals (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id INT UNSIGNED NOT NULL,

  -- Random per browser tab, not per person: someone may join twice.
  from_peer  VARCHAR(40) NOT NULL,
  -- Null addresses everyone in the room — used when announcing arrival.
  to_peer    VARCHAR(40) DEFAULT NULL,

  -- hello | offer | answer | ice | bye
  kind       VARCHAR(12) NOT NULL,
  payload    MEDIUMTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- The poll asks "anything for me since id N", which this serves directly.
  KEY idx_signal_room (meeting_id, id),
  KEY idx_signal_age (created_at),

  CONSTRAINT fk_signal_meeting FOREIGN KEY (meeting_id)
    REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Settings: when to remind, and the wording.
-- Existing values are never overwritten.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('meeting_reminder_mins',    '60,30'),
  ('notify_meeting_reminder_email', '1'),
  ('notify_meeting_reminder_sms',   '1'),
  -- Empty by default: public STUN is enough for most connections, and a
  -- relay is only needed where both ends sit behind strict NAT.
  ('webrtc_turn_url',      ''),
  ('webrtc_turn_username', ''),
  ('webrtc_turn_password', '')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('tpl_meeting_reminder_subject',
   '{meeting_title} starts in {minutes_to_start} minutes'),
  ('tpl_meeting_reminder_intro',
   '{meeting_title} is due to start at {meeting_time} on {meeting_date}, {minutes_to_start} minutes from now. Join from the link below — it opens in your browser, with nothing to install.'),
  ('tpl_sms_meeting_reminder',
   '{company}: {meeting_title} starts {meeting_time} ({minutes_to_start} min). Join: {join_link}')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
