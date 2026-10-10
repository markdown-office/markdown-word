<?php

declare(strict_types=1);

namespace MarkdownWord;

use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;

final class Configuration
{
    public function __construct(
        private readonly Styles $styles = new Styles(),
        private readonly Options $options = new Options(),
    ) {
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param array{styles?: array<string, mixed>, options?: array<string, mixed>} $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            isset($config['styles']) ? new Styles($config['styles']) : new Styles(),
            isset($config['options']) ? Options::fromArray($config['options']) : new Options(),
        );
    }

    public function getStyles(): Styles
    {
        return $this->styles;
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    public function withStyles(Styles|array $styles): self
    {
        return new self(
            $styles instanceof Styles ? $styles : $this->styles->withAll($styles),
            $this->options,
        );
    }

    public function withOptions(Options|array $options): self
    {
        return new self(
            $this->styles,
            $options instanceof Options ? $options : $this->options->withAll($options),
        );
    }

    /**
     * Merge a batch of configuration over this one.
     *
     * Both sub-objects are merged rather than replaced, and the merge reads a missing
     * key as "not mentioned" rather than as "reset". {@see Options::withAll()} is the
     * one that decides what a `null` means, and it reads it as "not mentioned" for the
     * same reason this does: the caller named a key and gave it no value, so reverting
     * it would change something they never mentioned. A `null` for a slot or an option
     * that accepts one is a value, and clears it.
     *
     * @param array{styles?: array<string, mixed>, options?: array<string, mixed>} $config
     */
    public function withAll(array $config): self
    {
        return new self(
            isset($config['styles']) ? $this->styles->withAll($config['styles']) : $this->styles,
            isset($config['options']) ? $this->options->withAll($config['options']) : $this->options,
        );
    }

    /**
     * Hand every one of these slots to Word's own styles instead of to the
     * {@see \MarkdownWord\Configuration\LookAndFeel}, so the document takes its
     * appearance from the template it is rendered into.
     *
     * This is the way back to what the library did before the Look & Feel existed:
     * a slot here holds nothing but a styleId, the paragraph is written with that
     * id and nothing else, and Word resolves the id against its own catalogue. An
     * `.odt` or an `.rtf` has no such catalogue for `Heading1` or `Quote`, so this
     * is the setting that gives those two their plain, unstyled text back.
     */
    public function withBuiltInHeadingStyles(): self
    {
        $headings = [];
        for ($level = 1; $level <= 6; $level++) {
            $headings['heading.' . $level] = 'Heading' . $level;
        }

        return $this->withStyles($headings + [
            Styles::BLOCK_QUOTE => 'Quote',
            Styles::BULLET_LIST => 'ListBullet',
            Styles::ORDERED_LIST => 'ListNumber',
            Styles::PARAGRAPH => null,
            Styles::LIST_PARAGRAPH => null,
            Styles::CODE_BLOCK => null,
        ]);
    }

    /**
     * Turns off what this library adds over Word's own styles: the code and link
     * fonts, the quote style, the spacing the body and the lists would otherwise
     * have, the table borders and the code-block shading. Everything still lands in
     * the document, which is the point when a template governs the look.
     *
     * The headings are left alone: they are the one slot whose default is a style
     * name as well as properties, and {@see self::withBuiltInHeadingStyles()} is
     * what hands those over too.
     */
    public function withoutDecoration(): self
    {
        return $this->withStyles([
            Styles::CODE_FONT => null,
            Styles::LINK_FONT => null,
            Styles::BLOCK_QUOTE => null,
            Styles::PARAGRAPH => null,
            Styles::LIST_PARAGRAPH => null,
            Styles::CODE_BLOCK => null,
        ])->withOptions([
            'tableBorders' => false,
            'codeBlockShading' => false,
        ]);
    }

    /**
     * @return array{styles: array<string, mixed>, options: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'styles' => $this->styles->toArray(),
            'options' => $this->options->toArray(),
        ];
    }
}
