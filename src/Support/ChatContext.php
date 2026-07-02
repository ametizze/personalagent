<?php

namespace App\Support;

/**
 * Carries the two identities of an incoming update, kept separate so the bot can
 * work in groups:
 *
 *  - $ownerId     : whose data this is — the Telegram *user* id (message.from.id).
 *                   Used as the per-person key for every model/query and config.
 *  - $replyChatId : where answers go — the *chat* id (message.chat.id), which is
 *                   the group in a group chat, or the user in a private chat.
 *
 * In a private chat both ids are identical, so single-user behaviour is unchanged.
 */
final class ChatContext
{
    public function __construct(
        public readonly int $ownerId,
        public readonly int $replyChatId,
        public readonly bool $isGroup,
        public string $lang,
    ) {}
}
