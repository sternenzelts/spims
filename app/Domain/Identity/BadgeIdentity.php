<?php

namespace App\Domain\Identity;

use Illuminate\Container\Attributes\Config;
use RuntimeException;

/**
 * How a badge UID is stored (design §8.2): an HMAC-SHA256 lookup key, an encrypted copy for System
 * Administrators, and a masked display form. A database leak alone yields no usable badge UIDs.
 */
final readonly class BadgeIdentity
{
    public function __construct(
        #[Config('spims.badge_pepper')] private ?string $pepper,
    ) {}

    public function hash(string $uid): string
    {
        if ($this->pepper === null || $this->pepper === '') {
            throw new RuntimeException('BADGE_PEPPER is not set. Generate one per install; see config/spims.php.');
        }

        return hash_hmac('sha256', $uid, $this->pepper);
    }

    /**
     * Show at most the first five characters and always hide the last four, so a nine-character
     * UID reads A1234**** as in the proposal's §3B sample.
     */
    public function mask(string $uid): string
    {
        $length = strlen($uid);
        $visible = max(0, min(5, $length - 4));

        return substr($uid, 0, $visible).str_repeat('*', $length - $visible);
    }

    /**
     * Personnel attributes for an enrolled badge. badge_uid_encrypted is given in plain text
     * because the model's encrypted cast encrypts it on save.
     *
     * @return array{badge_uid_hash: string, badge_uid_encrypted: string, badge_uid_masked: string}
     */
    public function attributesFor(string $uid): array
    {
        return [
            'badge_uid_hash' => $this->hash($uid),
            'badge_uid_encrypted' => $uid,
            'badge_uid_masked' => $this->mask($uid),
        ];
    }
}
