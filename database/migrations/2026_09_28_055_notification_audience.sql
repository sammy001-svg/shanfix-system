-- =====================================================================
-- Migration 055 — Telling a client's message from a colleague's
--
-- Two things were wrong with the outbound queue, and both came from
-- treating every message in it as the same kind of thing.
--
-- The first: cron refused to work the queue at all unless app.url was
-- set. That guard is right where it sits in the queueing step — a
-- client document with a link pointing at localhost is worse than one
-- sent tomorrow. It is wrong over the sending step, because a message
-- already in the queue has its body already written. The links in it
-- were built, or were not, when it was queued; refusing to post the
-- envelope now does not change what is inside it. So an office with no
-- app.url had every staff notification, every partner alert and every
-- renewal reminder sit at status 'queued' with attempts = 0, for ever,
-- with nothing on any screen to say why.
--
-- The second: notify_send_window held everything. It exists so a client
-- is not texted at three in the morning. It was also holding a
-- colleague's bell e-mail and a live chat transcript somebody asked for
-- thirty seconds earlier — neither of which wakes anybody.
--
-- Both need the same thing: knowing whose message it is. So each row
-- now says, rather than being guessed at from the event name, which is
-- a list that drifts.
--
--   client   — goes to somebody outside the company. Bound by the
--              sending window, because their phone is on their bedside
--              table.
--   internal — goes to a colleague or a partner working with us. Sent
--              when it is ready, because that is the point of it.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'notifications' AND column_name = 'audience');

SET @sql := IF(@col = 0,
  'ALTER TABLE notifications
     ADD COLUMN audience ENUM(''client'',''internal'') NOT NULL DEFAULT ''client''
         COMMENT ''Who it is for: decides whether the sending window applies''
         AFTER event',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Nothing already in the queue is backfilled, on purpose.
--
-- The obvious thing is to guess from the event name, and the first
-- draft of this did: anything starting partner_ or bulk_ or livechat_
-- was called internal. That is wrong in both directions, for exactly
-- the reason given above. 'partner_applied' tells our own staff that
-- somebody has applied; 'partner_paid' tells the partner we have paid
-- them. 'livechat_waiting' is the bell on a colleague's screen;
-- 'livechat_transcript' is the conversation going to the visitor who
-- asked for it. Same prefix, opposite audiences.
--
-- So every row already queued stays 'client', which is the careful
-- direction: at worst a colleague's message waits for the sending
-- window once, and only where a window is configured at all. Anything
-- queued from here is marked by whatever queued it, which knows.

-- The queue is asked for what is waiting, oldest first. Without this it
-- is a scan of every message ever sent, every fifteen minutes.
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name = 'notifications'
                AND index_name = 'idx_notify_waiting');

SET @sql := IF(@idx = 0,
  'ALTER TABLE notifications
     ADD KEY idx_notify_waiting (status, attempts, scheduled_at)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  -- How many to attempt in one run. Forty was chosen when nothing was
  -- failing; against a mail server that is refusing connections each
  -- one costs a timeout, and forty of those is most of the gap between
  -- cron runs. Raised, and paired with the give-up below.
  ('notify_batch_size', '60'),
  -- Consecutive transport failures before a run stops trying. When the
  -- mail server is down, the forty-first attempt fails exactly like the
  -- first — it just takes another two seconds to say so. Stopping early
  -- leaves the queue intact for the next run and keeps the log
  -- readable.
  ('notify_give_up_after', '5')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
