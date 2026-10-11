<?php

declare(strict_types=1);

namespace MarkdownWord\Theme;

use MarkdownWord\Exception\MalformedTheme;

/**
 * What a `theme.yml` says about itself.
 *
 * Metadata only. The look of a theme is `styles.yml` — the {@see \MarkdownWord\Configuration\Styles}
 * slots a `.docx` is drawn from — and this half deliberately holds no palette, no type
 * scale and no rules for turning either into slot values.
 *
 * That is the one place this half differs from the presentation half, and the reason is
 * worth stating. PHPWord writes `word/theme/theme1.xml` with a fixed scheme and no way to
 * change it (`Writer\Word2007\Part\Theme` writes its own twelve colours), and the renderer
 * writes colours straight onto the runs rather than referring to theme slots. A palette
 * therefore has nowhere to land except as direct formatting, and a set of rules deciding
 * which slot takes which palette entry would be a vocabulary nobody wrote down, applied
 * in a layer the reader of the file never sees. A theme that says `heading.1: {color: "…"}`
 * says what it means.
 */
final class Theme
{
    /** The keys a `theme.yml` may have. */
    public const KEYS = ['name', 'description', 'author'];

    private function __construct(
        private readonly string $name,
        private readonly ?string $description,
        private readonly ?string $author,
    ) {
    }

    /**
     * @throws MalformedTheme
     */
    public static function fromArray(array $data, string $file = 'theme.yml'): self
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw MalformedTheme::unknownKey($file, (string) $key, self::KEYS);
            }
        }

        $name = $data['name'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            throw MalformedTheme::missingKey($file, 'name');
        }

        return new self(trim($name), self::text($data, 'description', $file), self::text($data, 'author', $file));
    }

    /** The name a human reads, which is not the directory it was found in. */
    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function author(): ?string
    {
        return $this->author;
    }

    /**
     * @return array{name: string, description: ?string, author: ?string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'author' => $this->author];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     */
    private static function text(array $data, string $key, string $file): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw MalformedTheme::wrongType($file, $key, 'a string');
        }

        return $value;
    }
}