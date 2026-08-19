<?php
namespace App\Services;

/**
 * The questions we ask a client before starting work.
 *
 * Three briefs, one per kind of job the company takes on. They live here
 * in PHP rather than in the database because they are wording, and wording
 * gets edited — a question that turns out to confuse clients should be
 * fixable by changing a line here, not by writing a migration.
 *
 * The answers are stored against `key`, so renaming a key orphans the
 * answers already given under the old one. Change the label freely; leave
 * the key alone once briefs have been answered with it.
 *
 * Each field is:
 *   key      stored with the answer, and must not change
 *   label    the question as the client reads it
 *   type     text | textarea | select | checks | date | money
 *   hint     the sentence under the question, where one earns its place
 *   options  for select and checks
 *   required whether the client can send it back blank
 */
class JobBrief
{
    public const TYPES = [
        'design'  => 'Design & branding',
        'website' => 'Website',
        'system'  => 'System or application',
    ];

    /** What the client is told they are filling in. */
    public const HEADINGS = [
        'design'  => 'About the design you need',
        'website' => 'About the website you need',
        'system'  => 'About the system you need',
    ];

    public const BLURBS = [
        'design'  => 'The more you tell us here, the closer the first draft will be to what you had in mind — and the fewer rounds of changes it takes to get there.',
        'website' => 'This helps us quote accurately and build the right thing. If you are not sure about something, say so and leave it — we will talk it through.',
        'system'  => 'Tell us about the work the system is meant to take off your hands. How it is done today is often the most useful thing you can give us.',
    ];

    /** Every brief ends with the same three questions. */
    private const CLOSING = [
        [
            'key'   => 'deadline',
            'label' => 'When do you need it by?',
            'type'  => 'text',
            'hint'  => 'A date, or an event it has to be ready for.',
        ],
        [
            'key'   => 'budget',
            'label' => 'What budget do you have in mind?',
            'type'  => 'text',
            'hint'  => 'A range is fine. It tells us which options are worth showing you and which are not.',
        ],
        [
            'key'   => 'anything_else',
            'label' => 'Anything else we should know?',
            'type'  => 'textarea',
        ],
    ];

    /**
     * The questions for one brief.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fields(string $type): array
    {
        $method = 'brief' . ucfirst($type);

        if (!isset(self::TYPES[$type]) || !method_exists(self::class, $method)) {
            return [];
        }

        return array_merge(self::$method(), self::CLOSING);
    }

    /** Is this one of the three? */
    public static function isType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** The label for one field key, for rendering an answer back. */
    public static function labelFor(string $type, string $key): ?string
    {
        foreach (self::fields($type) as $f) {
            if ($f['key'] === $key) {
                return $f['label'];
            }
        }

        return null;
    }

    // -- The briefs --------------------------------------------------------

    /** Printing, branding, signage — anything the company designs. */
    private static function briefDesign(): array
    {
        return [
            [
                'key'      => 'what',
                'label'    => 'What do you need designed?',
                'type'     => 'checks',
                'required' => true,
                'options'  => [
                    'Logo', 'Business cards', 'Letterhead', 'Flyer or poster',
                    'Brochure or profile', 'Banner or backdrop', 'Signage',
                    'Vehicle branding', 'T-shirts or uniform', 'Packaging or labels',
                    'Social media artwork', 'Something else',
                ],
            ],
            [
                'key'      => 'business_name',
                'label'    => 'Business or product name, spelled exactly as it should appear',
                'type'     => 'text',
                'required' => true,
                'hint'     => 'Including capitals and any punctuation. This is what gets printed.',
            ],
            [
                'key'   => 'tagline',
                'label' => 'Tagline or strapline, if there is one',
                'type'  => 'text',
            ],
            [
                'key'      => 'what_you_do',
                'label'    => 'What does the business do?',
                'type'     => 'textarea',
                'required' => true,
                'hint'     => 'A sentence or two. It shapes the whole look.',
            ],
            [
                'key'   => 'audience',
                'label' => 'Who is it for?',
                'type'  => 'textarea',
                'hint'  => 'The people who will see it — their age, where they are, what they care about.',
            ],
            [
                'key'   => 'feel',
                'label' => 'How should it feel?',
                'type'  => 'checks',
                'options' => [
                    'Professional', 'Modern', 'Traditional', 'Bold', 'Simple',
                    'Playful', 'Premium', 'Friendly', 'Technical',
                ],
            ],
            [
                'key'   => 'colours',
                'label' => 'Colours you want used',
                'type'  => 'text',
                'hint'  => 'Existing brand colours if you have them. Exact codes are ideal, names are fine.',
            ],
            [
                'key'   => 'colours_avoid',
                'label' => 'Colours to avoid',
                'type'  => 'text',
            ],
            [
                'key'      => 'text_content',
                'label'    => 'Text that must appear',
                'type'     => 'textarea',
                'hint'     => 'Phone numbers, email, website, address, social handles, any slogan or wording. Please check it carefully — this is what we set.',
            ],
            [
                'key'   => 'sizes',
                'label' => 'Sizes and quantities',
                'type'  => 'textarea',
                'hint'  => 'For example: 500 business cards, one 3m x 1m banner. If you are unsure we will advise.',
            ],
            [
                'key'   => 'where_used',
                'label' => 'Where will it be used?',
                'type'  => 'checks',
                'options' => ['Printed', 'Outdoors', 'On screen', 'Social media', 'Vehicle', 'Clothing'],
            ],
            [
                'key'   => 'references',
                'label' => 'Designs you like',
                'type'  => 'textarea',
                'hint'  => 'Links, brands, or a description — and what it is about them you like. Attach examples below if you have them.',
            ],
            [
                'key'   => 'has_brand',
                'label' => 'Do you have existing brand materials?',
                'type'  => 'select',
                'options' => [
                    'No, this is new',
                    'Yes, and the new work must match them',
                    'Yes, but we want a fresh look',
                ],
            ],
        ];
    }

    /** Websites. */
    private static function briefWebsite(): array
    {
        return [
            [
                'key'      => 'site_type',
                'label'    => 'What kind of website?',
                'type'     => 'select',
                'required' => true,
                'options'  => [
                    'Company website — who we are, what we do',
                    'Online shop — selling products',
                    'Booking or appointments',
                    'Portfolio or gallery',
                    'Blog or news',
                    'Landing page for one product or campaign',
                    'Not sure yet',
                ],
            ],
            [
                'key'      => 'business_name',
                'label'    => 'Business name as it should appear on the site',
                'type'     => 'text',
                'required' => true,
            ],
            [
                'key'      => 'what_you_do',
                'label'    => 'What does the business do?',
                'type'     => 'textarea',
                'required' => true,
            ],
            [
                'key'   => 'goal',
                'label' => 'What should the website achieve?',
                'type'  => 'textarea',
                'hint'  => 'Enquiries, sales, bookings, credibility — what would make it worth having built.',
            ],
            [
                'key'   => 'audience',
                'label' => 'Who are the visitors?',
                'type'  => 'textarea',
            ],
            [
                'key'   => 'domain',
                'label' => 'Do you have a domain name?',
                'type'  => 'text',
                'hint'  => 'For example shanfix.co.ke. If you do not have one yet we can register it for you.',
            ],
            [
                'key'   => 'hosting',
                'label' => 'Hosting',
                'type'  => 'select',
                'options' => [
                    'We need hosting arranged',
                    'We already have hosting',
                    'Not sure',
                ],
            ],
            [
                'key'   => 'pages',
                'label' => 'What pages do you need?',
                'type'  => 'textarea',
                'hint'  => 'For example: Home, About, Services, Gallery, Contact. List what comes to mind — we will suggest the rest.',
            ],
            [
                'key'   => 'features',
                'label' => 'What should it be able to do?',
                'type'  => 'checks',
                'options' => [
                    'Contact form', 'M-Pesa payments', 'Card payments',
                    'Online shop and cart', 'Bookings or appointments',
                    'WhatsApp button', 'Live chat', 'Blog or news',
                    'Photo gallery', 'Downloads', 'Newsletter signup',
                    'Customer login area', 'Admin area to update it ourselves',
                    'More than one language', 'Google Maps',
                ],
            ],
            [
                'key'   => 'content_ready',
                'label' => 'Do you have the content ready?',
                'type'  => 'select',
                'options' => [
                    'Yes — text, photos and logo are ready',
                    'Some of it',
                    'No, we need help writing it',
                ],
                'hint'  => 'Attach whatever you have below.',
            ],
            [
                'key'   => 'references',
                'label' => 'Websites you like',
                'type'  => 'textarea',
                'hint'  => 'Addresses, and what it is about each one you like. Competitors are useful too.',
            ],
            [
                'key'   => 'who_updates',
                'label' => 'Who will keep it up to date after it launches?',
                'type'  => 'select',
                'options' => [
                    'We will, if it is easy enough',
                    'We would like you to look after it',
                    'It will not need changing often',
                ],
            ],
        ];
    }

    /** Custom systems and applications. */
    private static function briefSystem(): array
    {
        return [
            [
                'key'      => 'problem',
                'label'    => 'What should the system do?',
                'type'     => 'textarea',
                'required' => true,
                'hint'     => 'In your own words — the work it is meant to take off your hands.',
            ],
            [
                'key'      => 'current_process',
                'label'    => 'How is this handled today?',
                'type'     => 'textarea',
                'required' => true,
                'hint'     => 'Books, spreadsheets, another system, or nothing yet. This is usually the most useful thing you can tell us.',
            ],
            [
                'key'   => 'pain',
                'label' => 'What goes wrong with the way it is done now?',
                'type'  => 'textarea',
                'hint'  => 'The things that cost you time or money. These are what the system has to fix to be worth having.',
            ],
            [
                'key'      => 'users',
                'label'    => 'Who will use it, and how many people?',
                'type'     => 'textarea',
                'required' => true,
                'hint'     => 'Their roles as well as the number — an owner, three clerks and a storekeeper each need to see different things.',
            ],
            [
                'key'   => 'functions',
                'label' => 'What are the main things it needs to handle?',
                'type'  => 'checks',
                'options' => [
                    'Customers or members', 'Quotations and invoices', 'Payments and receipts',
                    'Stock and inventory', 'Purchases and suppliers', 'Staff records',
                    'Attendance', 'Bookings or scheduling', 'Deliveries',
                    'Expenses', 'Documents and files', 'Approvals',
                ],
            ],
            [
                'key'   => 'reports',
                'label' => 'What do you need to see out of it?',
                'type'  => 'textarea',
                'hint'  => 'The reports or figures you would look at daily, monthly, or at year end.',
            ],
            [
                'key'   => 'integrations',
                'label' => 'Does it need to work with anything else?',
                'type'  => 'checks',
                'options' => [
                    'M-Pesa', 'Bank', 'KRA / eTIMS', 'SMS', 'Email',
                    'WhatsApp', 'Accounting software', 'An existing system of ours',
                    'Barcode scanner', 'Printer or till',
                ],
            ],
            [
                'key'   => 'devices',
                'label' => 'Where will it be used?',
                'type'  => 'checks',
                'options' => [
                    'Office computers', 'Phones', 'Tablets',
                    'More than one branch', 'Somewhere with poor internet',
                ],
            ],
            [
                'key'   => 'existing_data',
                'label' => 'Is there existing data to bring across?',
                'type'  => 'textarea',
                'hint'  => 'Customer lists, stock, past invoices. Attach a sample below if you can — it tells us more than a description.',
            ],
        ];
    }
}
