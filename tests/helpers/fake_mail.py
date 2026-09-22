"""A stand-in cPanel mail server for the mailbox tests: IMAP and SMTP.

    python fake_mail.py <imap-port> <smtp-port>

Plain TCP on 127.0.0.1 (the system is told security "none" in tests), with
two accounts that look like cPanel/Dovecot ones: "INBOX" plus INBOX.Sent,
INBOX.Drafts, INBOX.Trash and INBOX.spam, "." as the delimiter.

    alice@shanfix.test  /  alice-pass-1
    bob@shanfix.test    /  bob-pass-2     (password has no special chars)
    zoe@shanfix.test    /  pässwörd-3     (sent as an IMAP literal)

Mail sent through SMTP is delivered into the INBOX of any of these
accounts it is addressed to, so a test can send as Alice and read as Bob.
Alice's INBOX is seeded with messages that exercise the reader: plain
text, hostile HTML, an attachment, and a subject in another language.

It implements only the commands the system's ImapClient sends, and
answers the way Dovecot does. Nothing here is fit for real mail.
"""
import base64, re, socket, sys, threading, time

IMAP_PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 59143
SMTP_PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 59025

ACCOUNTS = {
    "alice@shanfix.test": "alice-pass-1",
    "bob@shanfix.test": "bob-pass-2",
    "zoe@shanfix.test": "pässwörd-3",
}
FOLDERS = ["INBOX", "INBOX.Sent", "INBOX.Drafts", "INBOX.Trash", "INBOX.spam"]
SPECIAL = {"INBOX.Sent": "\\Sent", "INBOX.Drafts": "\\Drafts", "INBOX.Trash": "\\Trash", "INBOX.spam": "\\Junk"}

lock = threading.Lock()
store = {a: {f: [] for f in FOLDERS} for a in ACCOUNTS}   # account -> folder -> [msg]
next_uid = {a: {f: 1 for f in FOLDERS} for a in ACCOUNTS}


def deliver(account, folder, raw, flags=()):
    with lock:
        uid = next_uid[account][folder]
        next_uid[account][folder] += 1
        store[account][folder].append({"uid": uid, "raw": raw, "flags": set(flags),
                                       "date": time.strftime("%d-%b-%Y %H:%M:%S +0300")})
        return uid


def seed():
    crlf = "\r\n"
    deliver("alice@shanfix.test", "INBOX", crlf.join([
        "From: Client One <client.one@example.com>", "To: alice@shanfix.test",
        "Subject: Quote for 500 flyers", "Date: Mon, 21 Sep 2026 09:00:00 +0300",
        "Message-ID: <seed1@example.com>", "Content-Type: text/plain; charset=UTF-8", "",
        "Hello Alice,", "", "Please send a quote for 500 A5 flyers, full colour.", "", "Thanks"]) + crlf)

    deliver("alice@shanfix.test", "INBOX", crlf.join([
        "From: Newsletter <news@tracker.example>", "To: alice@shanfix.test",
        "Subject: Hostile HTML", "Date: Mon, 21 Sep 2026 10:00:00 +0300",
        "Message-ID: <seed2@tracker.example>", "MIME-Version: 1.0",
        'Content-Type: multipart/related; boundary="REL"', "",
        "--REL", "Content-Type: text/html; charset=UTF-8", "",
        '<p onclick="steal()">Big sale <b>today</b></p><script>alert("xss")</script>'
        '<img src="https://tracker.example/pixel.gif?u=alice">'
        '<a href="javascript:alert(1)">bad link</a> <a href="https://example.com/offer">good link</a>'
        '<img src="cid:logo123">',
        "--REL", "Content-Type: image/png", "Content-Transfer-Encoding: base64",
        "Content-ID: <logo123>", "",
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
        "--REL--"]) + crlf)

    deliver("alice@shanfix.test", "INBOX", crlf.join([
        "From: =?UTF-8?B?Sm9zw6kgTcO8bGxlcg==?= <jose@example.com>", "To: alice@shanfix.test",
        "Subject: =?UTF-8?Q?Invoice_attached_=E2=80=94_Septemba?=", "Date: Mon, 21 Sep 2026 11:00:00 +0300",
        "Message-ID: <seed3@example.com>", "MIME-Version: 1.0",
        'Content-Type: multipart/mixed; boundary="MIX"', "",
        "--MIX", "Content-Type: text/plain; charset=ISO-8859-1", "Content-Transfer-Encoding: quoted-printable", "",
        "Caf=E9 invoice attached.",
        "--MIX", 'Content-Type: application/pdf; name="invoice-001.pdf"', "Content-Transfer-Encoding: base64",
        'Content-Disposition: attachment; filename="invoice-001.pdf"', "",
        base64.b64encode(b"%PDF-1.4 fake invoice").decode(),
        "--MIX--"]) + crlf, flags=("\\Seen",))


# ---------------------------------------------------------------------------
# IMAP
# ---------------------------------------------------------------------------

def tokens(s):
    """Split an IMAP command's arguments: atoms, quoted strings, (lists)."""
    out, i = [], 0
    while i < len(s):
        c = s[i]
        if c == " ":
            i += 1
        elif c == '"':
            j, buf = i + 1, ""
            while j < len(s) and s[j] != '"':
                if s[j] == "\\":
                    j += 1
                buf += s[j]
                j += 1
            out.append(buf)
            i = j + 1
        elif c == "(":
            depth, j = 1, i + 1
            while depth:
                depth += {"(": 1, ")": -1}.get(s[j], 0)
                j += 1
            out.append(s[i:j])
            i = j
        else:
            j = i
            depth = 0
            while j < len(s) and (s[j] != " " or depth):
                depth += {"[": 1, "]": -1}.get(s[j], 0)
                j += 1
            out.append(s[i:j])
            i = j
    return out


def uid_set(spec, msgs):
    want = set()
    for part in spec.split(","):
        if ":" in part:
            a, b = part.split(":")
            hi = max((m["uid"] for m in msgs), default=0)
            a = int(a); b = hi if b == "*" else int(b)
            want.update(range(min(a, b), max(a, b) + 1))
        else:
            want.add(int(part))
    return [m for m in msgs if m["uid"] in want]


def header_fields(raw, names):
    head = raw.replace("\r\n", "\n").split("\n\n", 1)[0]
    lines = []
    for line in head.split("\n"):
        # A folded continuation belongs to the header above it.
        if line[:1] in (" ", "\t") and lines:
            lines[-1] += "\r\n" + line
            continue
        lines.append(line)
    keep = [l for l in lines if l.split(":", 1)[0].strip().upper() in names]
    return "\r\n".join(keep) + "\r\n\r\n"


def matches(msg, crit):
    text = msg["raw"].lower()
    for field, needle in crit:
        if field == "UNSEEN" and "\\Seen" in msg["flags"]:
            return False
    needles = [n for f, n in crit if f == "TEXT"]
    if needles:
        head = msg["raw"].replace("\r\n", "\n").split("\n\n", 1)[0].lower()
        return needles[0].lower() in head
    return True


class ImapSession(threading.Thread):
    def __init__(self, conn):
        super().__init__(daemon=True)
        self.c = conn
        self.f = conn.makefile("rb")
        self.user = None
        self.folder = None

    def send(self, s):
        self.c.sendall(s.encode("utf-8") if isinstance(s, str) else s)

    def read_command(self):
        """One command, with any {n} literals read in (after '+ go ahead')."""
        line = self.f.readline()
        if not line:
            return None
        buf = b""
        literals = []
        while True:
            m = re.search(rb"\{(\d+)\}\r\n$", line)
            if not m:
                buf += line.rstrip(b"\r\n")
                break
            buf += line[:m.start()]
            self.send("+ go ahead\r\n")
            data = self.f.read(int(m.group(1)))
            literals.append(data)
            buf += b"\x01" + str(len(literals) - 1).encode() + b"\x01"
            line = self.f.readline()
        return buf.decode("utf-8", "replace"), literals

    def run(self):
        self.send("* OK [CAPABILITY IMAP4rev1 LITERAL+ SPECIAL-USE] Dovecot ready.\r\n")
        try:
            while True:
                got = self.read_command()
                if got is None:
                    return
                line, lits = got
                if not line.strip():
                    continue
                tag, _, rest = line.partition(" ")
                cmd, _, args = rest.partition(" ")
                cmd = cmd.upper()
                if cmd == "UID":
                    sub, _, args = args.partition(" ")
                    cmd = "UID " + sub.upper()
                if not self.handle(tag, cmd, args, lits):
                    return
        except (ConnectionError, OSError):
            return
        finally:
            self.c.close()

    def lit(self, token, lits):
        m = re.fullmatch("\x01(\\d+)\x01", token)
        return lits[int(m.group(1))].decode("utf-8", "replace") if m else token

    def ok(self, tag, text="Completed"):
        self.send(f"{tag} OK {text}\r\n")

    def handle(self, tag, cmd, args, lits):
        acct = store.get(self.user) if self.user else None

        if cmd == "CAPABILITY":
            self.send("* CAPABILITY IMAP4rev1 LITERAL+ SPECIAL-USE MOVE UIDPLUS\r\n")
            self.ok(tag)
        elif cmd == "LOGIN":
            t = tokens(args)
            user = self.lit(t[0], lits)
            pw = self.lit(t[1], lits) if len(t) > 1 else ""
            if ACCOUNTS.get(user) == pw:
                self.user = user
                self.ok(tag, "Logged in")
            else:
                self.send(f"{tag} NO [AUTHENTICATIONFAILED] Authentication failed.\r\n")
        elif cmd == "LOGOUT":
            self.send("* BYE Logging out\r\n")
            self.ok(tag)
            return False
        elif acct is None:
            self.send(f"{tag} BAD Log in first\r\n")
        elif cmd == "LIST":
            for f in acct:
                flags = "\\HasNoChildren" + (" " + SPECIAL[f] if f in SPECIAL else "")
                self.send(f'* LIST ({flags}) "." "{f}"\r\n')
            self.ok(tag)
        elif cmd == "STATUS":
            name = self.lit(tokens(args)[0], lits)
            msgs = acct.get(name, [])
            unseen = sum(1 for m in msgs if "\\Seen" not in m["flags"])
            self.send(f'* STATUS "{name}" (MESSAGES {len(msgs)} UNSEEN {unseen})\r\n')
            self.ok(tag)
        elif cmd in ("SELECT", "EXAMINE"):
            name = self.lit(tokens(args)[0], lits)
            if name not in acct:
                self.send(f"{tag} NO Mailbox doesn't exist: {name}\r\n")
                return True
            self.folder = name
            self.send(f"* {len(acct[name])} EXISTS\r\n* OK [UIDVALIDITY 1] UIDs valid\r\n")
            self.ok(tag, "[READ-ONLY] Examined" if cmd == "EXAMINE" else "[READ-WRITE] Selected")
        elif cmd == "CREATE":
            name = self.lit(tokens(args)[0], lits)
            acct.setdefault(name, [])
            self.ok(tag)
        elif cmd == "UID SEARCH":
            msgs = acct[self.folder]
            t = [self.lit(x, lits) for x in tokens(args)]
            crit = []
            if "UNSEEN" in [x.upper() for x in t]:
                crit.append(("UNSEEN", None))
            if "FROM" in [x.upper() for x in t]:
                crit.append(("TEXT", t[[x.upper() for x in t].index("FROM") + 1]))
            hits = [m["uid"] for m in msgs if matches(m, crit)]
            self.send("* SEARCH" + "".join(" " + str(u) for u in hits) + "\r\n")
            self.ok(tag)
        elif cmd == "UID FETCH":
            spec, _, what = args.partition(" ")
            for m in uid_set(spec, acct[self.folder]):
                seq = acct[self.folder].index(m) + 1
                parts = [f"UID {m['uid']}"]
                if "FLAGS" in what:
                    parts.append("FLAGS (" + " ".join(sorted(m["flags"])) + ")")
                if "RFC822.SIZE" in what:
                    parts.append(f"RFC822.SIZE {len(m['raw'].encode())}")
                if "INTERNALDATE" in what:
                    parts.append(f'INTERNALDATE "{m["date"]}"')
                body = None
                hm = re.search(r"BODY\.PEEK\[HEADER\.FIELDS \(([^)]*)\)\]", what)
                if hm:
                    names = hm.group(1).split()
                    data = header_fields(m["raw"], [n.upper() for n in names]).encode()
                    body = f"BODY[HEADER.FIELDS ({hm.group(1)})]"
                elif "BODY.PEEK[]" in what:
                    data = m["raw"].encode()
                    body = "BODY[]"
                if body:
                    head = f"* {seq} FETCH (" + " ".join(parts) + f" {body} {{{len(data)}}}\r\n"
                    self.send(head.encode() + data + b")\r\n")
                else:
                    self.send(f"* {seq} FETCH (" + " ".join(parts) + ")\r\n")
            self.ok(tag)
        elif cmd == "UID STORE":
            spec, op, flags = args.split(" ", 2)
            flagset = set(flags.strip("()").split())
            for m in uid_set(spec, acct[self.folder]):
                if op.startswith("+"):
                    m["flags"] |= flagset
                else:
                    m["flags"] -= flagset
            self.ok(tag)
        elif cmd in ("UID MOVE", "UID COPY"):
            spec, _, dest = args.partition(" ")
            dest = self.lit(tokens(dest)[0], lits)
            if dest not in acct:
                self.send(f"{tag} NO [TRYCREATE] Mailbox doesn't exist\r\n")
                return True
            for m in uid_set(spec, acct[self.folder]):
                deliver(self.user, dest, m["raw"], m["flags"] - {"\\Deleted"})
                if cmd == "UID MOVE":
                    acct[self.folder].remove(m)
            self.ok(tag)
        elif cmd in ("UID EXPUNGE", "EXPUNGE"):
            acct[self.folder][:] = [m for m in acct[self.folder] if "\\Deleted" not in m["flags"]]
            self.ok(tag)
        elif cmd == "APPEND":
            t = tokens(args)
            name = self.lit(t[0], lits)
            flags = t[1].strip("()").split() if len(t) > 2 and t[1].startswith("(") else []
            raw = self.lit(t[-1], lits)
            if name not in acct:
                self.send(f"{tag} NO [TRYCREATE] Mailbox doesn't exist\r\n")
                return True
            uid = deliver(self.user, name, raw, flags)
            self.ok(tag, f"[APPENDUID 1 {uid}] Append completed")
        else:
            self.send(f"{tag} BAD Unknown command {cmd}\r\n")
        return True


# ---------------------------------------------------------------------------
# SMTP
# ---------------------------------------------------------------------------

class SmtpSession(threading.Thread):
    def __init__(self, conn):
        super().__init__(daemon=True)
        self.c = conn
        self.f = conn.makefile("rb")

    def send(self, s):
        self.c.sendall(s.encode())

    def line(self):
        l = self.f.readline()
        if not l:
            raise ConnectionError()
        return l.decode("utf-8", "replace").rstrip("\r\n")

    def run(self):
        try:
            self.send("220 mail.shanfix.test ESMTP\r\n")
            user, rcpts, mail_from = None, [], None
            while True:
                l = self.line()
                u = l.upper()
                if u.startswith("EHLO") or u.startswith("HELO"):
                    self.send("250-mail.shanfix.test\r\n250 AUTH LOGIN PLAIN\r\n")
                elif u == "AUTH LOGIN":
                    self.send("334 VXNlcm5hbWU6\r\n")
                    name = base64.b64decode(self.line()).decode()
                    self.send("334 UGFzc3dvcmQ6\r\n")
                    pw = base64.b64decode(self.line()).decode()
                    if ACCOUNTS.get(name) == pw:
                        user = name
                        self.send("235 Authentication succeeded\r\n")
                    else:
                        self.send("535 Incorrect authentication data\r\n")
                elif u.startswith("MAIL FROM"):
                    if not user:
                        self.send("530 Authentication required\r\n")
                        continue
                    mail_from = l
                    rcpts = []
                    self.send("250 OK\r\n")
                elif u.startswith("RCPT TO"):
                    addr = re.search(r"<([^>]+)>", l).group(1).lower()
                    if addr.endswith("@refused.test"):
                        self.send("550 No such user here\r\n")
                    else:
                        rcpts.append(addr)
                        self.send("250 Accepted\r\n")
                elif u == "DATA":
                    self.send("354 Enter message, ending with a dot\r\n")
                    lines = []
                    while True:
                        l = self.line()
                        if l == ".":
                            break
                        lines.append(l[1:] if l.startswith("..") else l)
                    raw = "\r\n".join(lines) + "\r\n"
                    for r in rcpts:
                        if r in ACCOUNTS:
                            deliver(r, "INBOX", raw)
                    self.send("250 OK id=fake\r\n")
                elif u == "QUIT":
                    self.send("221 Bye\r\n")
                    return
                else:
                    self.send("250 OK\r\n")
        except (ConnectionError, OSError):
            return
        finally:
            self.c.close()


def serve(port, cls):
    s = socket.socket()
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind(("127.0.0.1", port))
    s.listen(20)
    while True:
        conn, _ = s.accept()
        cls(conn).start()


if __name__ == "__main__":
    seed()
    threading.Thread(target=serve, args=(SMTP_PORT, SmtpSession), daemon=True).start()
    print(f"fake mail: IMAP {IMAP_PORT}, SMTP {SMTP_PORT}", flush=True)
    serve(IMAP_PORT, ImapSession)
