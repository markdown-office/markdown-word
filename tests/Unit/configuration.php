<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Parser\MarkdownParserInterface;
use MarkdownWord\Render\ParagraphStyle;
use MarkdownWord\Text\TextExtractor;
use League\CommonMark\Node\Block\Document;

it('defaults are sensible', function () {
    $styles = Configuration::create()->getStyles();
    $defaults = Styles::defaults();

    // The default slots are the Look & Feel: properties, with the Word style id
    // named inside them so a `.docx` heading is still a `Heading 1`.
    expect($styles->get(Styles::HEADING_1))->toBe($defaults[Styles::HEADING_1]);
    expect(ParagraphStyle::styleNameOf($styles->get(Styles::HEADING_1)))->toBe(['styleName' => 'Heading1']);
    expect(ParagraphStyle::styleNameOf($styles->get(Styles::HEADING_6)))->toBe(['styleName' => 'Heading6']);
    expect($styles->get(Styles::HEADING_1)['bold'])->toBeTrue();
    expect($styles->get(Styles::HEADING_1)['size'])->toBe(16);
    expect(Configuration::create()->getOptions()->softBreak)->toBe(Options::SOFT_BREAK_SPACE);
});

it('styles are overridden without touching the rest', function () {
    $config = Configuration::create()->withStyles([Styles::HEADING_1 => 'Report Title']);

    expect($config->getStyles()->get(Styles::HEADING_1))->toBe('Report Title');
    expect($config->getStyles()->get(Styles::HEADING_2))->toBe(Styles::defaults()[Styles::HEADING_2]);
});

it('options are overridden without touching the rest', function () {
    $config = Configuration::create()->withOptions(['tableBorders' => false]);

    expect($config->getOptions()->tableBorders)->toBeFalse();
    expect($config->getOptions()->tableHeaderBold)->toBeTrue();
});

it('configuration is immutable', function () {
    $original = Configuration::create();
    $modified = $original->withStyles([Styles::HEADING_1 => 'X']);

    expect($original->getStyles()->get(Styles::HEADING_1))->toBe(Styles::defaults()[Styles::HEADING_1]);
    expect($modified->getStyles()->get(Styles::HEADING_1))->toBe('X');
});

it('it can be built from a plain array', function () {
    $config = Configuration::fromArray([
        'styles' => [Styles::CODE_FONT => ['name' => 'Fira Code']],
        'options' => ['maxHeadingLevel' => 3, 'tableBorders' => false],
    ]);

    expect($config->getStyles()->get(Styles::CODE_FONT))->toBe(['name' => 'Fira Code']);
    expect($config->getOptions()->maxHeadingLevel)->toBe(3);
    expect($config->getOptions()->tableBorders)->toBeFalse();
});

it('loosely typed values from config files are coerced', function () {
    $config = Configuration::fromArray([
        'options' => [
            'maxHeadingLevel' => '9',
            'imageMaxWidth' => '12.5',
            'tableBorders' => 0,
            'imageBasePath' => '',
        ],
    ]);

    $options = $config->getOptions();

    expect($options->maxHeadingLevel)->toBe(6);
    expect($options->imageMaxWidth)->toBe(12.5);
    expect($options->tableBorders)->toBeFalse();
    expect($options->imageBasePath)->toBeNull();
});

it('an array survives a round trip', function () {
    $config = Configuration::create()
        ->withStyles([Styles::HEADING_1 => 'Title'])
        ->withOptions(['tableBorders' => false]);

    $restored = Configuration::fromArray($config->toArray());

    expect($restored->getStyles()->get(Styles::HEADING_1))->toBe('Title');
    expect($restored->getOptions()->tableBorders)->toBeFalse();
});

it('decoration can be switched off entirely', function () {
    $config = Configuration::create()->withoutDecoration();

    expect($config->getStyles()->get(Styles::CODE_FONT))->toBeNull();
    expect($config->getStyles()->get(Styles::BLOCK_QUOTE))->toBeNull();
    // The body and list spacing is decoration too, and `--plain` reads this method
    // rather than its own list, so leaving it on would leave the flag half true.
    expect($config->getStyles()->get(Styles::PARAGRAPH))->toBeNull();
    expect($config->getStyles()->get(Styles::LIST_PARAGRAPH))->toBeNull();
    expect($config->getOptions()->tableBorders)->toBeFalse();
});

it('built in heading styles can be restored', function () {
    $config = Configuration::create()->withBuiltInHeadingStyles();

    expect($config->getStyles()->get(Styles::HEADING_1))->toBe('Heading1');
    expect($config->getStyles()->get(Styles::BULLET_LIST))->toBe('ListBullet');
});

it('a heading level can be explicitly unstyled', function () {
    // Setting a level to null means "do not give this heading a style of its
    // own", which is different from leaving it at the Word default.
    $styles = new Styles([
        Styles::HEADING_1 => 'Title',
        Styles::HEADING_4 => null,
        Styles::PARAGRAPH => 'Body',
    ]);

    expect($styles->heading(1))->toBe('Title');
    expect($styles->heading(4))->toBe('Body');
});

it('unknown option keys are ignored rather than fatal', function () {
    $config = Configuration::fromArray(['options' => ['noSuchOption' => true]]);

    expect($config->getOptions()->softBreak)->toBe(Options::SOFT_BREAK_SPACE);
});

it('the text extractor recovers rendered content', function () {
    $document = (new \MarkdownWord\MarkdownToWord())->toPhpWord("# Title\n\nSome **bold** text.\n");

    expect(TextExtractor::fromPhpWord($document))->toBe("Title\nSome bold text.");
});

it('a custom parser can be supplied', function () {
    $parser = new class implements MarkdownParserInterface {
        public function parse(string $markdown): Document
        {
            $environment = new \League\CommonMark\Environment\Environment();
            $environment->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());

            return (new \League\CommonMark\Parser\MarkdownParser($environment))->parse('# Only this');
        }
    };

    $document = (new \MarkdownWord\MarkdownToWord(null, new Configuration(), $parser))->toPhpWord('ignored');

    expect(TextExtractor::fromPhpWord($document))->toBe('Only this');
});

it('the common mark only parser leaves gfm syntax alone', function () {
    $converter = new \MarkdownWord\MarkdownToWord(
        null,
        new Configuration(),
        CommonMarkParser::commonMarkOnly(),
    );

    // Without the table extension the delimiter row is an ordinary paragraph,
    // so the pipes survive as text rather than becoming a table.
    $text = TextExtractor::fromPhpWord(
        $converter->toPhpWord("| a | b |\n| --- | --- |\n| 1 | 2 |"),
    );

    expect($text)->toContain('|');
});

it('the extended parser adds footnotes', function () {
    $converter = new \MarkdownWord\MarkdownToWord(
        null,
        new Configuration(),
        CommonMarkParser::extended(),
    );

    expect(TextExtractor::fromPhpWord($converter->toPhpWord("Text[^1]\n\n[^1]: note\n")))->toContain('note');
});
