<?php

declare(strict_types=1);

namespace MarkdownWord\Render\Directive;

/**
 * What a document asked of a writer that may not be able to carry it.
 *
 * The features are the keys {@see \MarkdownWord\Format} asks about, and they are
 * recorded here rather than read back off the element tree — because the element
 * is not there. A directive a writer cannot draw is given the characters the
 * author wrote, so nothing of it reaches the tree for a survey to find, and the
 * survey is what decides whether a document is told that it lost something.
 *
 * So a directive records itself here the moment it is used, whatever the format
 * is: whether that is a loss is the format's question and not the directive's.
 */
final class Usage
{
    /** @var array<string, true> */
    private array $used = [];

    /**
     * That a document used a feature.
     */
    public function add(string $feature): void
    {
        $this->used[$feature] = true;
    }

    /**
     * Whether a document used a feature.
     */
    public function uses(string $feature): bool
    {
        return isset($this->used[$feature]);
    }

    /**
     * Every feature a document used.
     *
     * @return list<string>
     */
    public function features(): array
    {
        return array_keys($this->used);
    }
}
