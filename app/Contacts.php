<?php
declare(strict_types=1);

namespace DPS;

/**
 * The people the switch can write to, stored in contacts.json.
 *
 * Kinds: exactly one next of kin, at least one technical contact, and any
 * number of legal or other contacts. Every contact carries an editable role
 * label, so the list can match how a particular business is handed over.
 */
final class Contacts
{
    public const KINDS = [
        'nok' => 'Next of kin',
        'technical' => 'Technical 2IC',
        'legal' => 'Legal contact',
        'other' => 'Other contact',
    ];

    public function __construct(private Store $store)
    {
    }

    /** @return array<int, array> next of kin first, then technical, legal, other */
    public function all(): array
    {
        $list = array_values(array_filter((array) ($this->store->read('contacts')['contacts'] ?? []), 'is_array'));
        $order = array_flip(array_keys(self::KINDS));
        usort($list, static fn (array $a, array $b): int =>
            [$order[$a['kind']] ?? 9, $a['created_at'] ?? 0] <=> [$order[$b['kind']] ?? 9, $b['created_at'] ?? 0]);
        return $list;
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $contact) {
            if (($contact['id'] ?? '') === $id) {
                return $contact;
            }
        }
        return null;
    }

    public function nok(): ?array
    {
        foreach ($this->all() as $contact) {
            if ($contact['kind'] === 'nok') {
                return $contact;
            }
        }
        return null;
    }

    /** @return array<int, array> contacts who have confirmed, and so will be written to */
    public function confirmed(): array
    {
        return array_values(array_filter($this->all(), static fn (array $c): bool => ($c['status'] ?? '') === 'confirmed'));
    }

    public function confirmedOfKind(string $kind): array
    {
        return array_values(array_filter($this->confirmed(), static fn (array $c): bool => $c['kind'] === $kind));
    }

    /** A new, unsaved contact with the starter wording for its kind. */
    public static function blank(string $kind): array
    {
        $kind = isset(self::KINDS[$kind]) ? $kind : 'other';
        $contact = [
            'id' => 'c_' . bin2hex(random_bytes(5)),
            'kind' => $kind,
            'role_label' => self::KINDS[$kind],
            'name' => '',
            'email' => '',
            'phone' => '',
            'pending_email' => null,
            'status' => 'unconfirmed',      // unconfirmed | confirmed | declined
            'token' => null,                // {hash, expires_at, purpose}
            'confirm_sent_at' => null,
            'confirmed_at' => null,
            'reconfirmed_at' => null,
            'reconfirm_asked_at' => null,
            'reconfirm_nudged_at' => null,
            'has_needs_at' => null,         // when the owner last ticked "has what they need"
            'message' => Messages::defaults($kind),
            'created_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ];
        if ($kind === 'nok') {
            $contact['verify_message'] = Messages::verifyDefault();
        }
        return $contact;
    }

    public function save(array $contact): void
    {
        $this->store->locked(function () use ($contact): void {
            $doc = $this->store->read('contacts');
            $list = array_values(array_filter((array) ($doc['contacts'] ?? []), 'is_array'));
            $contact['updated_at'] = Clock::now();
            $found = false;
            foreach ($list as $i => $existing) {
                if (($existing['id'] ?? '') === $contact['id']) {
                    $list[$i] = $contact;
                    $found = true;
                }
            }
            if (!$found) {
                $list[] = $contact;
            }
            $this->store->write('contacts', ['contacts' => $list]);
        });
    }

    public function delete(string $id): void
    {
        $this->store->locked(function () use ($id): void {
            $doc = $this->store->read('contacts');
            $list = array_values(array_filter(
                (array) ($doc['contacts'] ?? []),
                static fn ($c): bool => is_array($c) && ($c['id'] ?? '') !== $id
            ));
            $this->store->write('contacts', ['contacts' => $list]);
        });
    }

    /** Find the contact holding this emailed token, if it is still valid. */
    public function forToken(string $plain): ?array
    {
        foreach ($this->all() as $contact) {
            if (is_array($contact['token'] ?? null) && Tokens::matches($plain, [$contact['token']])) {
                return $contact;
            }
        }
        return null;
    }

    /** The role label as it reads mid-sentence: "next of kin", "technical 2IC", or whatever the owner typed. */
    public static function role(array $contact): string
    {
        $label = trim((string) ($contact['role_label'] ?? ''));
        return match ($label) {
            'Next of kin' => 'next of kin',
            'Technical 2IC' => 'technical 2IC',
            'Legal contact' => 'legal contact',
            'Other contact', '' => 'contact',
            default => $label,
        };
    }

    /** "Role: Name, email, phone" for use in messages to other contacts. */
    public static function line(array $contact): string
    {
        $parts = array_filter([$contact['name'] ?? '', $contact['email'] ?? '', $contact['phone'] ?? '']);
        return ($contact['role_label'] ?? 'Contact') . ': ' . implode(', ', $parts);
    }

    /** "Name, email, phone" */
    public static function details(?array $contact): string
    {
        if ($contact === null) {
            return '(none listed)';
        }
        return implode(', ', array_filter([$contact['name'] ?? '', $contact['email'] ?? '', $contact['phone'] ?? '']));
    }
}
