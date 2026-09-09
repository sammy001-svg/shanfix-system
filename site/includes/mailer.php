<?php
/*
 * Links in these messages point at the system, not at this site.
 *
 * The site used to run a client portal and an admin of its own; both
 * have been removed and the system behind this site is the only one of
 * each now. A customer following "View my portal" from an email needs to
 * arrive at the portal that actually holds their invoices.
 */
/**
 * Shanfix Technology - Mailer
 *
 * Uses SMTP (via stream sockets) when MAIL_HOST/MAIL_USER/MAIL_PASS are set in .env.
 * Falls back to PHP mail() on unconfigured environments.
 *
 * Usage:
 *   Mailer::invoiceCreated($name, $email, $ref, $amount, $due_date);
 *   Mailer::ticketReply($name, $email, $ref, $subject, $replier);
 *   Mailer::clientReplied($admin_email, $client_name, $ref, $subject);
 *   Mailer::welcome($name, $email);
 *   Mailer::renewalReminder($name, $email, $service, $due_date, $amount);
 */

require_once __DIR__ . '/brand.php';

class Mailer {

    // -------------------------------------------------------------------------
    // Public template methods
    // -------------------------------------------------------------------------

    public static function welcome(string $name, string $email): bool {
        $subject = 'Welcome to Shanfix Technology Portal';
        $html = self::layout('Welcome Aboard, ' . htmlspecialchars($name) . '!', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Your Shanfix client portal account has been created. You can now log in to view invoices,
                manage your services, and contact support.
            </p>
            <div style="background:#f8fafc; border-radius:12px; padding:20px; margin:24px 0; border-left:4px solid #6366f1;">
                <p style="margin:0; font-size:0.9rem; color:#64748b;"><strong>Login Email:</strong> ' . htmlspecialchars($email) . '</p>
            </div>
            <p style="color:#475569; font-size:0.9rem;">If you did not create this account, please ignore this email.</p>
        ', 'Log In to Portal', $_ENV['APP_URL'] . '/portal/login');
        return self::send($email, $subject, $html);
    }

    public static function invoiceCreated(string $name, string $email, string $ref, float $amount, string $due_date): bool {
        $subject = "Invoice {$ref} from Shanfix Technology";
        $formatted = 'KES ' . number_format($amount, 2);
        $html = self::layout("New Invoice: {$ref}", '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>, a new invoice has been issued to your account.
            </p>
            <div style="background:#f8fafc; border-radius:12px; padding:24px; margin:24px 0;">
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem;">Invoice Reference</td>
                        <td style="padding:8px 0; font-weight:700; text-align:right;">' . htmlspecialchars($ref) . '</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #e2e8f0;">Amount Due</td>
                        <td style="padding:8px 0; font-weight:800; font-size:1.2rem; color:#6366f1; text-align:right; border-top:1px solid #e2e8f0;">' . $formatted . '</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #e2e8f0;">Due Date</td>
                        <td style="padding:8px 0; font-weight:700; color:#ef4444; text-align:right; border-top:1px solid #e2e8f0;">' . htmlspecialchars($due_date) . '</td>
                    </tr>
                </table>
            </div>
            <p style="color:#475569; font-size:0.9rem;">
                <strong>Payment:</strong> M-PESA Till No. <strong>5698666</strong> or log in to your portal to download the invoice PDF.
            </p>
        ', 'View Invoice', $_ENV['APP_URL'] . '/portal/login');
        return self::send($email, $subject, $html);
    }

    public static function ticketReply(string $name, string $email, string $ticket_ref, string $subject, string $replier_name): bool {
        $mail_subject = "Re: [{$ticket_ref}] " . $subject;
        $html = self::layout('New Reply on Your Ticket', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>,
                <strong>' . htmlspecialchars($replier_name) . '</strong> from Shanfix Support has replied to your ticket.
            </p>
            <div style="background:#f8fafc; border-radius:12px; padding:20px; margin:24px 0; border-left:4px solid #22c55e;">
                <p style="margin:0 0 6px 0; font-size:0.8rem; color:#64748b; text-transform:uppercase; font-weight:700;">Ticket</p>
                <p style="margin:0; font-weight:700; color:#1e293b;">[' . htmlspecialchars($ticket_ref) . '] ' . htmlspecialchars($subject) . '</p>
            </div>
            <p style="color:#475569; font-size:0.9rem;">Log in to your portal to read the full reply and respond.</p>
        ', 'View Ticket', $_ENV['APP_URL'] . '/portal/login');
        return self::send($email, $mail_subject, $html);
    }

    public static function passwordReset(string $name, string $email, string $reset_url, string $role = 'client'): bool {
        $subject = 'Reset Your Password — Shanfix Technology';
        $panel   = $role === 'admin' ? 'Admin Panel' : 'Client Portal';
        $html = self::layout('Password Reset Request', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>, we received a request to reset the password for your Shanfix ' . $panel . ' account.
            </p>
            <p style="color:#475569; font-size:0.95rem; line-height:1.7;">
                Click the button below to set a new password. This link expires in <strong>30 minutes</strong>.
            </p>
            <div style="background:#fef3c7; border-radius:12px; padding:16px 20px; margin:24px 0; border-left:4px solid #f59e0b;">
                <p style="margin:0; font-size:0.85rem; color:#92400e;">
                    <strong>Did not request this?</strong> You can safely ignore this email. Your password will not change.
                </p>
            </div>
        ', 'Reset My Password', $reset_url);
        return self::send($email, $subject, $html);
    }

    public static function clientApproved(string $name, string $email): bool {
        $subject = 'Your Shanfix Portal Account is Approved!';
        $html = self::layout('Welcome — You\'re Approved!', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>, great news! Your Shanfix Technology client portal account has been reviewed and <strong style="color:#16a34a;">approved</strong>.
            </p>
            <p style="color:#475569; font-size:0.95rem; line-height:1.7;">
                You can now log in to view your invoices, track your services, and contact our support team.
            </p>
            <div style="background:#f0fdf4; border-radius:12px; padding:16px 20px; margin:24px 0; border-left:4px solid #22c55e;">
                <p style="margin:0; font-size:0.9rem; color:#166534;">
                    <strong>Login Email:</strong> ' . htmlspecialchars($email) . '
                </p>
            </div>
        ', 'Log In to My Portal', $_ENV['APP_URL'] . '/portal/login');
        return self::send($email, $subject, $html);
    }

    public static function clientRejected(string $name, string $email, string $reason = ''): bool {
        $b = site_brand();
        $subject = 'Update on Your Shanfix Portal Access Request';
        $reasonBlock = $reason ? '
            <div style="background:#fef3c7; border-radius:12px; padding:16px 20px; margin:24px 0; border-left:4px solid #f59e0b;">
                <p style="margin:0 0 4px; font-size:0.8rem; font-weight:700; color:#92400e; text-transform:uppercase;">Reason</p>
                <p style="margin:0; color:#78350f; font-size:0.9rem;">' . htmlspecialchars($reason) . '</p>
            </div>' : '';
        $html = self::layout('Portal Access Request Update', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>, after reviewing your access request we are unable to approve your Shanfix Technology client portal account at this time.
            </p>
            ' . $reasonBlock . '
            <p style="color:#475569; font-size:0.95rem; line-height:1.7;">
                If you believe this is an error or would like to discuss further, please contact us directly at <a href="mailto:' . htmlspecialchars($b['email']) . '" style="color:#6366f1;">' . htmlspecialchars($b['email']) . '</a> or call <strong>' . htmlspecialchars($b['phone']) . '</strong>.
            </p>
        ');
        return self::send($email, $subject, $html);
    }

    public static function newClientRegistration(string $admin_email, string $client_name, string $client_email): bool {
        $subject = 'New Client Registration Awaiting Approval';
        $html = self::layout('New Registration Request', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                A new client has self-registered and is awaiting approval.
            </p>
            <div style="background:#f8fafc; border-radius:12px; padding:20px; margin:24px 0; border-left:4px solid #6366f1;">
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; width:100px;">Name</td>
                        <td style="padding:8px 0; font-weight:700;">' . htmlspecialchars($client_name) . '</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #e2e8f0;">Email</td>
                        <td style="padding:8px 0; border-top:1px solid #e2e8f0;">' . htmlspecialchars($client_email) . '</td>
                    </tr>
                </table>
            </div>
            <p style="color:#475569; font-size:0.9rem;">Log in to the admin panel to review and approve this request.</p>
        ', 'Open Admin Clients', $_ENV['APP_URL'] . '/clients');
        return self::send($admin_email, $subject, $html);
    }

    public static function newTicket(string $admin_email, string $client_name, string $ticket_ref, string $subject, string $priority): bool {
        $mail_subject = "[New Ticket] [{$ticket_ref}] " . $subject;
        $priorityColor = match(strtolower($priority)) {
            'high', 'critical' => '#ef4444',
            'medium'           => '#f59e0b',
            default            => '#64748b',
        };
        $html = self::layout('New Support Ticket Opened', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                A new support ticket has been submitted by <strong>' . htmlspecialchars($client_name) . '</strong>.
            </p>
            <div style="background:#f8fafc; border-radius:12px; padding:20px; margin:24px 0; border-left:4px solid ' . $priorityColor . ';">
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; width:120px;">Reference</td>
                        <td style="padding:8px 0; font-weight:700;">' . htmlspecialchars($ticket_ref) . '</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #e2e8f0;">Subject</td>
                        <td style="padding:8px 0; font-weight:700; border-top:1px solid #e2e8f0;">' . htmlspecialchars($subject) . '</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #e2e8f0;">Priority</td>
                        <td style="padding:8px 0; font-weight:800; color:' . $priorityColor . '; border-top:1px solid #e2e8f0;">' . strtoupper($priority) . '</td>
                    </tr>
                </table>
            </div>
            <p style="color:#475569; font-size:0.9rem;">Log in to the admin panel to view the full message and respond.</p>
        ', 'Open Admin Panel', $_ENV['APP_URL'] . '/requests');
        return self::send($admin_email, $mail_subject, $html);
    }

    public static function clientReplied(string $admin_email, string $client_name, string $ticket_ref, string $subject): bool {
        $mail_subject = "[{$ticket_ref}] Client replied: " . $subject;
        $html = self::layout('Client Replied to a Ticket', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                <strong>' . htmlspecialchars($client_name) . '</strong> has posted a new reply on their support ticket.
            </p>
            <div style="background:#f8fafc; border-radius:12px; padding:20px; margin:24px 0; border-left:4px solid #f59e0b;">
                <p style="margin:0 0 6px 0; font-size:0.8rem; color:#64748b; text-transform:uppercase; font-weight:700;">Ticket</p>
                <p style="margin:0; font-weight:700; color:#1e293b;">[' . htmlspecialchars($ticket_ref) . '] ' . htmlspecialchars($subject) . '</p>
            </div>
        ', 'Open in Admin', $_ENV['APP_URL'] . '/requests');
        return self::send($admin_email, $mail_subject, $html);
    }

    public static function renewalReminder(string $name, string $email, string $service_name, string $due_date, float $amount = 0): bool {
        $subject = "Service Renewal Due: {$service_name}";
        $amountLine = $amount > 0 ? '<tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #e2e8f0;">Renewal Amount</td>
                        <td style="padding:8px 0; font-weight:800; color:#6366f1; text-align:right; border-top:1px solid #e2e8f0;">KES ' . number_format($amount, 2) . '</td>
                    </tr>' : '';
        $html = self::layout('Service Renewal Reminder', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>, your service is due for renewal soon.
                Please ensure payment is made before the due date to avoid any service interruption.
            </p>
            <div style="background:#fff7ed; border-radius:12px; padding:24px; margin:24px 0; border-left:4px solid #f59e0b;">
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem;">Service</td>
                        <td style="padding:8px 0; font-weight:700; text-align:right;">' . htmlspecialchars($service_name) . '</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #fed7aa;">Due Date</td>
                        <td style="padding:8px 0; font-weight:800; color:#ea580c; text-align:right; border-top:1px solid #fed7aa;">' . htmlspecialchars($due_date) . '</td>
                    </tr>
                    ' . $amountLine . '
                </table>
            </div>
            <p style="color:#475569; font-size:0.9rem;">
                Pay via <strong>M-PESA Till No. 5698666</strong> or contact us for assistance.
            </p>
        ', 'Manage Services', $_ENV['APP_URL'] . '/portal/login');
        return self::send($email, $subject, $html);
    }

    public static function serviceStatusChanged(string $name, string $email, string $service_name, string $new_status): bool {
        $subject = "Service Update: {$service_name}";
        $accentColor = match(strtolower($new_status)) {
            'active'     => '#22c55e',
            'pending'    => '#f59e0b',
            'suspended'  => '#ef4444',
            'terminated' => '#94a3b8',
            default      => '#6366f1',
        };
        $message = match(strtolower($new_status)) {
            'active'     => 'Your service has been activated and is ready to use.',
            'pending'    => 'Your service is pending activation. Our team will reach out shortly.',
            'suspended'  => 'Your service has been suspended. Please contact us or settle any outstanding balance to restore access.',
            'terminated' => 'Your service has been terminated. Please contact us if you believe this is an error.',
            default      => 'Your service status has been updated.',
        };
        $html = self::layout("Service Status Update: {$service_name}", '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>, there has been an update to one of your services.
            </p>
            <div style="background:#f8fafc; border-radius:12px; padding:24px; margin:24px 0; border-left:4px solid ' . $accentColor . ';">
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; width:120px;">Service</td>
                        <td style="padding:8px 0; font-weight:700;">' . htmlspecialchars($service_name) . '</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #e2e8f0;">New Status</td>
                        <td style="padding:8px 0; font-weight:800; color:' . $accentColor . '; text-transform:uppercase; border-top:1px solid #e2e8f0;">' . htmlspecialchars($new_status) . '</td>
                    </tr>
                </table>
            </div>
            <p style="color:#475569; font-size:0.95rem; line-height:1.7;">' . $message . '</p>
        ', 'View My Portal', $_ENV['APP_URL'] . '/portal/login');
        return self::send($email, $subject, $html);
    }

    public static function paymentConfirmed(string $name, string $email, string $invoice_ref, float $amount, string $mpesa_receipt): bool {
        $subject = "Payment Confirmed — {$invoice_ref}";
        $formatted = 'KES ' . number_format($amount, 2);
        $html = self::layout('Payment Received!', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>, we have received your payment. Thank you!
            </p>
            <div style="background:#f0fdf4; border-radius:12px; padding:24px; margin:24px 0; border-left:4px solid #22c55e;">
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem;">Invoice</td>
                        <td style="padding:8px 0; font-weight:700; text-align:right;">' . htmlspecialchars($invoice_ref) . '</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #bbf7d0;">Amount Paid</td>
                        <td style="padding:8px 0; font-weight:800; font-size:1.2rem; color:#16a34a; text-align:right; border-top:1px solid #bbf7d0;">' . $formatted . '</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #bbf7d0;">M-PESA Receipt</td>
                        <td style="padding:8px 0; font-weight:700; color:#1e293b; text-align:right; border-top:1px solid #bbf7d0;">' . htmlspecialchars($mpesa_receipt) . '</td>
                    </tr>
                </table>
            </div>
            <p style="color:#475569; font-size:0.9rem;">
                A receipt has been generated in your client portal. Log in to view your account history.
            </p>
        ', 'View My Portal', $_ENV['APP_URL'] . '/portal/login');
        return self::send($email, $subject, $html);
    }

    public static function contactAutoReply(string $name, string $email, string $subject): bool {
        $b = site_brand();
        $mail_subject = "We received your message — Shanfix Technology";
        $html = self::layout('Thanks for reaching out!', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>, we\'ve received your enquiry about
                <em>"' . htmlspecialchars($subject) . '"</em>.
            </p>
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Our team will review your message and get back to you within <strong>24 hours</strong>.
                In the meantime, feel free to call us directly at <strong>' . htmlspecialchars($b['phone']) . '</strong>.
            </p>
            <div style="background:#f8fafc; border-radius:12px; padding:16px; margin:24px 0; border-left:4px solid #22c55e;">
                <p style="margin:0; font-size:0.9rem; color:#64748b;">
                    <i>This is an automated confirmation. Please do not reply to this email.</i>
                </p>
            </div>
        ');
        return self::send($email, $mail_subject, $html);
    }

    public static function contactAdminNotify(string $admin_email, string $name, string $from_email, string $subject, string $message): bool {
        $mail_subject = "New Contact: {$subject}";
        $html = self::layout('New Contact Form Submission', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                A new enquiry has been submitted on the Shanfix website.
            </p>
            <div style="background:#f8fafc; border-radius:12px; padding:20px; margin:24px 0;">
                <table style="width:100%; border-collapse:collapse;">
                    <tr><td style="padding:8px 0; color:#64748b; font-size:0.85rem; width:100px;">From</td><td style="padding:8px 0; font-weight:700;">' . htmlspecialchars($name) . '</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; font-size:0.85rem; border-top:1px solid #e2e8f0;">Email</td><td style="padding:8px 0; border-top:1px solid #e2e8f0;">' . htmlspecialchars($from_email) . '</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; font-size:0.85rem; border-top:1px solid #e2e8f0;">Subject</td><td style="padding:8px 0; border-top:1px solid #e2e8f0; font-weight:700;">' . htmlspecialchars($subject) . '</td></tr>
                </table>
            </div>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin:0 0 24px;">
                <p style="margin:0 0 8px; font-size:0.75rem; text-transform:uppercase; font-weight:700; color:#64748b;">Message</p>
                <p style="margin:0; color:#1e293b; line-height:1.7;">' . nl2br(htmlspecialchars($message)) . '</p>
            </div>
        ', 'Open Admin Inbox', $_ENV['APP_URL'] . '/leads');
        return self::send($admin_email, $mail_subject, $html);
    }

    public static function contactReply(string $name, string $email, string $original_subject, string $reply): bool {
        $b = site_brand();
        $mail_subject = "Re: {$original_subject} — Shanfix Technology";
        $html = self::layout('Response from Shanfix Technology', '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>, our team has responded to your enquiry.
            </p>
            <div style="background:#f8fafc; border-radius:12px; padding:20px; margin:24px 0; border-left:4px solid #6366f1;">
                <p style="margin:0 0 8px; font-size:0.75rem; text-transform:uppercase; font-weight:700; color:#64748b;">Our Response</p>
                <p style="margin:0; color:#1e293b; line-height:1.7;">' . nl2br(htmlspecialchars($reply)) . '</p>
            </div>
            <p style="color:#475569; font-size:0.9rem;">
                If you have further questions, feel free to reply to this email or call <strong>' . htmlspecialchars($b['phone']) . '</strong>.
            </p>
        ');
        return self::send($email, $mail_subject, $html);
    }

    // -------------------------------------------------------------------------
    // Core send + SMTP
    // -------------------------------------------------------------------------

    public static function buildEventConfirmation(string $name, string $eventTitle, string $eventDate, string $venue, string $tickets, string $ref, float $total): string {
        $totalFmt = $total > 0 ? 'KES ' . number_format($total, 2) : 'FREE';
        return self::layout("🎟 Booking Confirmed — {$eventTitle}", '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>, your tickets have been confirmed! See you at the event.
            </p>
            <div style="background:#f0fdf4; border-radius:12px; padding:24px; margin:24px 0; border-left:4px solid #22c55e;">
                <table style="width:100%; border-collapse:collapse;">
                    <tr><td style="padding:8px 0; color:#64748b; font-size:0.9rem; width:130px;">Booking Ref</td>
                        <td style="padding:8px 0; font-weight:800; color:#166534; font-size:1.1rem;">' . htmlspecialchars($ref) . '</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #bbf7d0;">Event</td>
                        <td style="padding:8px 0; font-weight:700; border-top:1px solid #bbf7d0;">' . htmlspecialchars($eventTitle) . '</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #bbf7d0;">Date</td>
                        <td style="padding:8px 0; font-weight:600; border-top:1px solid #bbf7d0;">' . htmlspecialchars($eventDate) . '</td></tr>
                    ' . ($venue ? '<tr><td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #bbf7d0;">Venue</td>
                        <td style="padding:8px 0; border-top:1px solid #bbf7d0;">' . htmlspecialchars($venue) . '</td></tr>' : '') . '
                    <tr><td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #bbf7d0;">Tickets</td>
                        <td style="padding:8px 0; border-top:1px solid #bbf7d0;">' . htmlspecialchars($tickets) . '</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; font-size:0.9rem; border-top:1px solid #bbf7d0;">Total Paid</td>
                        <td style="padding:8px 0; font-weight:800; color:#16a34a; font-size:1.1rem; border-top:1px solid #bbf7d0;">' . $totalFmt . '</td></tr>
                </table>
            </div>
            <div style="background:#fef3c7; border-radius:10px; padding:14px 18px; margin:0 0 20px; border-left:4px solid #f59e0b;">
                <p style="margin:0; color:#92400e; font-size:0.875rem;">
                    <strong>Your entry code is: ' . htmlspecialchars($ref) . '</strong><br>
                    Present this code at the entrance for verification.
                </p>
            </div>
        ', 'View Event Details', site_url('event-ticketing.php'));
    }

    public static function send(string $to, string $subject, string $html): bool {
        $cfg = self::cfg();
        if (!empty($cfg['host']) && !empty($cfg['user']) && !empty($cfg['pass'])) {
            return self::smtp($to, $subject, $html, $cfg);
        }
        return self::native($to, $subject, $html, $cfg);
    }

    private static function cfg(): array {
        return [
            'host'      => $_ENV['MAIL_HOST']      ?? '',
            'port'      => (int)($_ENV['MAIL_PORT'] ?? 587),
            'user'      => $_ENV['MAIL_USER']      ?? '',
            'pass'      => $_ENV['MAIL_PASS']      ?? '',
            'from'      => $_ENV['MAIL_FROM']      ?? 'noreply@shanfixtechnology.com',
            'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'Shanfix Technology',
        ];
    }

    private static function smtp(string $to, string $subject, string $html, array $cfg): bool {
        try {
            $port   = $cfg['port'];
            $prefix = $port === 465 ? 'ssl://' : 'tcp://';
            $sock   = @fsockopen($prefix . $cfg['host'], $port, $errno, $errstr, 15);
            if (!$sock) {
                error_log("Mailer: could not connect to {$cfg['host']}:{$port} — {$errstr}");
                return false;
            }

            $read = static function () use ($sock): string {
                $buf = '';
                while ($line = fgets($sock, 512)) {
                    $buf .= $line;
                    if (isset($line[3]) && $line[3] === ' ') break;
                }
                return $buf;
            };
            $cmd = static function (string $c) use ($sock, $read): string {
                fputs($sock, $c . "\r\n");
                return $read();
            };

            $read(); // greeting
            $cmd('EHLO ' . $cfg['host']);

            if ($port !== 465) {
                $cmd('STARTTLS');
                stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $cmd('EHLO ' . $cfg['host']);
            }

            $cmd('AUTH LOGIN');
            $cmd(base64_encode($cfg['user']));
            $r = $cmd(base64_encode($cfg['pass']));
            if (substr(trim($r), 0, 3) !== '235') {
                error_log('Mailer: AUTH failed — ' . trim($r));
                fclose($sock);
                return false;
            }

            $cmd("MAIL FROM:<{$cfg['from']}>");
            $cmd("RCPT TO:<{$to}>");
            $cmd('DATA');

            $msg  = "From: {$cfg['from_name']} <{$cfg['from']}>\r\n";
            $msg .= "To: <{$to}>\r\n";
            $msg .= "Subject: {$subject}\r\n";
            $msg .= "MIME-Version: 1.0\r\n";
            $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
            $msg .= "\r\n" . $html . "\r\n.\r\n";
            fputs($sock, $msg);

            $r = $read();
            $cmd('QUIT');
            fclose($sock);

            return substr(trim($r), 0, 3) === '250';
        } catch (Throwable $e) {
            error_log('Mailer SMTP error: ' . $e->getMessage());
            return false;
        }
    }

    private static function native(string $to, string $subject, string $html, array $cfg): bool {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$cfg['from_name']} <{$cfg['from']}>\r\n";
        $headers .= "Reply-To: {$cfg['from']}\r\n";
        $headers .= "X-Mailer: Shanfix-PHP\r\n";
        return @mail($to, $subject, $html, $headers);
    }

    // -------------------------------------------------------------------------
    // Attachment support (MIME multipart/mixed)
    // -------------------------------------------------------------------------

    public static function sendInvoicePdf(string $name, string $email, string $ref, string $pdfBase64): bool {
        $subject = "Invoice {$ref} — Shanfix Technology";
        $html = self::layout("Your Invoice is Attached", '
            <p style="color:#475569; font-size:1rem; line-height:1.7;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>, please find your invoice
                <strong>' . htmlspecialchars($ref) . '</strong> attached to this email as a PDF.
            </p>
            <div style="background:#f8fafc; border-radius:12px; padding:20px; margin:24px 0; border-left:4px solid #6366f1;">
                <p style="margin:0; font-size:0.9rem; color:#64748b;">
                    <strong>M-PESA Till No. 5698666</strong> &nbsp;&bull;&nbsp; Reference: ' . htmlspecialchars($ref) . '
                </p>
            </div>
            <p style="color:#475569; font-size:0.9rem;">
                Log in to your portal to view all your invoices and payment history.
            </p>
        ', 'Visit Your Portal', $_ENV['APP_URL'] . '/portal/login');

        return self::sendWithAttachment($email, $subject, $html, $pdfBase64, "Invoice_{$ref}.pdf");
    }

    public static function sendWithAttachment(string $to, string $subject, string $html, string $pdfBase64, string $filename): bool {
        $cfg      = self::cfg();
        $boundary = '----=_Part_' . md5(microtime(true) . $to);

        $mime  = "--{$boundary}\r\n";
        $mime .= "Content-Type: text/html; charset=UTF-8\r\n";
        $mime .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $mime .= $html . "\r\n\r\n";
        $mime .= "--{$boundary}\r\n";
        $mime .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
        $mime .= "Content-Transfer-Encoding: base64\r\n";
        $mime .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
        $mime .= chunk_split($pdfBase64, 76, "\r\n") . "\r\n";
        $mime .= "--{$boundary}--";

        if (!empty($cfg['host']) && !empty($cfg['user']) && !empty($cfg['pass'])) {
            return self::smtpAttachment($to, $subject, $mime, $boundary, $cfg);
        }
        return self::nativeAttachment($to, $subject, $mime, $boundary, $cfg);
    }

    private static function smtpAttachment(string $to, string $subject, string $mime, string $boundary, array $cfg): bool {
        try {
            $port   = $cfg['port'];
            $prefix = $port === 465 ? 'ssl://' : 'tcp://';
            $sock   = @fsockopen($prefix . $cfg['host'], $port, $errno, $errstr, 15);
            if (!$sock) return false;

            $read = static function () use ($sock): string {
                $buf = '';
                while ($line = fgets($sock, 512)) {
                    $buf .= $line;
                    if (isset($line[3]) && $line[3] === ' ') break;
                }
                return $buf;
            };
            $cmd = static function (string $c) use ($sock, $read): string {
                fputs($sock, $c . "\r\n");
                return $read();
            };

            $read();
            $cmd('EHLO ' . $cfg['host']);
            if ($port !== 465) {
                $cmd('STARTTLS');
                stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $cmd('EHLO ' . $cfg['host']);
            }
            $cmd('AUTH LOGIN');
            $cmd(base64_encode($cfg['user']));
            $r = $cmd(base64_encode($cfg['pass']));
            if (substr(trim($r), 0, 3) !== '235') { fclose($sock); return false; }

            $cmd("MAIL FROM:<{$cfg['from']}>");
            $cmd("RCPT TO:<{$to}>");
            $cmd('DATA');

            $msg  = "From: {$cfg['from_name']} <{$cfg['from']}>\r\n";
            $msg .= "To: <{$to}>\r\n";
            $msg .= "Subject: {$subject}\r\n";
            $msg .= "MIME-Version: 1.0\r\n";
            $msg .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
            $msg .= "\r\n" . $mime . "\r\n.\r\n";
            fputs($sock, $msg);

            $r = $read();
            $cmd('QUIT');
            fclose($sock);
            return substr(trim($r), 0, 3) === '250';
        } catch (Throwable $e) {
            error_log('Mailer SMTP attachment error: ' . $e->getMessage());
            return false;
        }
    }

    private static function nativeAttachment(string $to, string $subject, string $mime, string $boundary, array $cfg): bool {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
        $headers .= "From: {$cfg['from_name']} <{$cfg['from']}>\r\n";
        $headers .= "Reply-To: {$cfg['from']}\r\n";
        return @mail($to, $subject, $mime, $headers);
    }

    // -------------------------------------------------------------------------
    // HTML layout wrapper (table-based for email client compatibility)
    // -------------------------------------------------------------------------

    private static function layout(string $heading, string $body, string $btn_text = '', string $btn_url = ''): string {
        $btn = $btn_text ? '
            <div style="text-align:center; margin:32px 0 8px;">
                <a href="' . htmlspecialchars($btn_url) . '"
                   style="display:inline-block; background:#6366f1; color:#ffffff; text-decoration:none;
                          font-weight:700; font-size:0.95rem; padding:14px 36px; border-radius:50px;
                          font-family:Arial,sans-serif;">
                    ' . htmlspecialchars($btn_text) . '
                </a>
            </div>' : '';

        return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%;">

      <!-- Header -->
      <tr>
        <td style="background:#6366f1; border-radius:16px 16px 0 0; padding:28px 40px; text-align:center;">
          <span style="font-size:1.5rem; font-weight:900; color:#ffffff; letter-spacing:-0.5px;">
            Shanfix <span style="color:#a5f3fc;">Technology</span>
          </span>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="background:#ffffff; padding:40px; border-radius:0 0 16px 16px; box-shadow:0 4px 24px rgba(0,0,0,0.06);">
          <h2 style="margin:0 0 20px 0; color:#1e293b; font-size:1.3rem; font-weight:800;">' . $heading . '</h2>
          ' . $body . '
          ' . $btn . '
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="padding:24px 40px; text-align:center;">
          <p style="margin:0; color:#94a3b8; font-size:0.8rem;">
            &copy; ' . date('Y') . ' Shanfix Technology Limited &bull; Nairobi, Kenya<br>
            <a href="mailto:' . htmlspecialchars(site_brand()['email']) . '" style="color:#6366f1; text-decoration:none;">' . htmlspecialchars(site_brand()['email']) . '</a>
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>';
    }
}
