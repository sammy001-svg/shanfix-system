<?php
namespace App\Services;

use App\Core\Database;

/**
 * Finding the people named in a message.
 *
 * Deliberately matches only against those already in the conversation.
 * Resolving @names against the whole staff list would let a message in a
 * private channel notify somebody who cannot open it — they would get an
 * alert linking to a page that refuses them.
 */
class Mentions
{
    /**
     * A name can be written several ways, so all of these match Jane Wanjiru:
     *
     *   @Jane            first name
     *   @JaneWanjiru     run together
     *   @Jane.Wanjiru    dotted
     *   @"Jane Wanjiru"  quoted, for names with spaces
     *
     * Longest candidates are tried first, so @JaneWanjiru is not consumed
     * by a shorter @Jane belonging to somebody else.
     *
     * @param array<int,array{id:int,name:string}> $members
     * @return array<int,int> user ids named in the body
     */
    public static function find(string $body, array $members): array
    {
        if (trim($body) === '' || $members === []) {
            return [];
        }

        $lower = mb_strtolower($body);
        $hits  = [];

        // @everyone reaches the room, which is the point of a channel.
        if (preg_match('/(^|\s)@(everyone|channel|all)\b/i', $body) === 1) {
            return array_map(static fn(array $m): int => (int) $m['id'], $members);
        }

        $candidates = [];

        foreach ($members as $member) {
            $name  = trim((string) $member['name']);
            $id    = (int) $member['id'];
            $first = explode(' ', $name)[0];

            foreach (array_unique([
                $name,
                str_replace(' ', '', $name),
                str_replace(' ', '.', $name),
                $first,
            ]) as $form) {
                if ($form === '') {
                    continue;
                }
                $candidates[] = ['text' => mb_strtolower($form), 'id' => $id];
            }
        }

        // Longest first: @JaneWanjiru must win over @Jane.
        usort($candidates, static fn(array $a, array $b): int
            => mb_strlen($b['text']) <=> mb_strlen($a['text']));

        foreach ($candidates as $candidate) {
            $quoted = preg_quote($candidate['text'], '/');

            // A trailing boundary stops @Jan matching inside @Janet, and the
            // leading one stops an email address counting as a mention.
            $pattern = '/(^|[^\w@])@"?' . $quoted . '"?(?![\w.])/iu';

            if (preg_match($pattern, $lower) === 1) {
                $hits[$candidate['id']] = true;
            }
        }

        return array_keys($hits);
    }

    /** Everyone in a conversation, as mention candidates. */
    public static function membersOf(int $conversationId): array
    {
        return Database::all(
            'SELECT u.id, u.name
               FROM chat_participants p
               JOIN users u ON u.id = p.user_id
              WHERE p.conversation_id = :id AND u.is_active = 1',
            ['id' => $conversationId]
        );
    }

    /**
     * Wrap mentions in markup so they stand out when the message is read.
     *
     * Takes text that is already escaped — the caller escapes first, so a
     * message containing markup cannot inject any here.
     */
    public static function highlight(string $escapedBody, array $members): string
    {
        if ($members === []) {
            return $escapedBody;
        }

        $forms = ['everyone', 'channel', 'all'];

        foreach ($members as $member) {
            $name = trim((string) $member['name']);

            foreach ([$name, str_replace(' ', '', $name), str_replace(' ', '.', $name),
                      explode(' ', $name)[0]] as $form) {
                if ($form !== '') {
                    $forms[] = $form;
                }
            }
        }

        $forms = array_unique($forms);

        usort($forms, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($forms as $form) {
            $escapedBody = preg_replace(
                '/(^|[^\w@])@("?)(' . preg_quote(htmlspecialchars($form, ENT_QUOTES, 'UTF-8'), '/') . ')\2(?![\w.])/iu',
                '$1<span class="mention">@$3</span>',
                $escapedBody
            );
        }

        return $escapedBody;
    }
}
