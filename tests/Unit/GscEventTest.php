<?php

use App\Gsc\EventRepository;

/**
 * EventRepository::sanitize normalises raw user input for a Search Analytics
 * timeline event: it enforces a valid ISO date + a non-empty title, trims and
 * length-caps the strings, and collapses a blank description to null. Pure — no
 * DB — so it runs standalone.
 */

it('accepts a valid event and trims its fields', function () {
    $out = EventRepository::sanitize('2026-06-15', '  Refonte du site  ', '  Nouvelle nav  ');
    expect($out)->toBe([
        'event_date'  => '2026-06-15',
        'title'       => 'Refonte du site',
        'description' => 'Nouvelle nav',
    ]);
});

it('treats a null or blank description as null', function () {
    expect(EventRepository::sanitize('2026-01-01', 'x', null)['description'])->toBeNull();
    expect(EventRepository::sanitize('2026-01-01', 'x', '   ')['description'])->toBeNull();
});

it('rejects a malformed date', function () {
    EventRepository::sanitize('15/06/2026', 'x', null);
})->throws(InvalidArgumentException::class, 'invalid_date');

it('rejects an impossible calendar date', function () {
    EventRepository::sanitize('2026-02-30', 'x', null);
})->throws(InvalidArgumentException::class, 'invalid_date');

it('rejects a non-ISO/short date', function () {
    EventRepository::sanitize('2026-6-1', 'x', null);
})->throws(InvalidArgumentException::class, 'invalid_date');

it('rejects an empty or whitespace-only title', function () {
    EventRepository::sanitize('2026-06-15', '   ', 'desc');
})->throws(InvalidArgumentException::class, 'empty_title');

it('caps the title at TITLE_MAX characters', function () {
    $long = str_repeat('a', EventRepository::TITLE_MAX + 50);
    $out = EventRepository::sanitize('2026-06-15', $long, null);
    expect(mb_strlen($out['title']))->toBe(EventRepository::TITLE_MAX);
});

it('caps the description at DESC_MAX characters', function () {
    $long = str_repeat('b', EventRepository::DESC_MAX + 50);
    $out = EventRepository::sanitize('2026-06-15', 'title', $long);
    expect(mb_strlen($out['description']))->toBe(EventRepository::DESC_MAX);
});

it('preserves multibyte titles without truncating mid-character', function () {
    $out = EventRepository::sanitize('2026-06-15', 'Refonte é à ù 🚀', null);
    expect($out['title'])->toBe('Refonte é à ù 🚀');
});
