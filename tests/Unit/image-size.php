<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The size of a picture, read out of the file rather than out of the number the
 * renderer handed to PHPWord.
 *
 * PHPWord measures an image in pixels, and its RTF and ODF writers read the number
 * back as pixels. Its Word writer labels the same number `pt`, so a picture of 96
 * pixels was laid out 96 points wide — a third too large, because a point is a
 * 72nd of an inch and a pixel a 96th of one. The two writers disagreed about the
 * same picture in the same document, and only the `.docx` was wrong.
 *
 * Every assertion here is made against the written file. The number the renderer
 * passed to PHPWord is not the evidence: the defect was precisely that the number
 * and the file disagreed, so a test that read the number would have passed against
 * the broken code.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * A picture of a given size in pixels, and the directory holding it.
 */
function sizedPicture(int $width, int $height): string
{
    $directory = Scratch::path('picture', '');
    mkdir($directory, 0o777, true);

    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 0x8B, 0x1A, 0x1A));
    imagepng($image, $directory . '/picture.png');

    return $directory;
}

/**
 * The size of a picture, in points, as each of the three formats writes it.
 *
 * @return array{docx: float, rtf: float, odt: float}
 */
function pictureSizesInPoints(string $markdown, ?Configuration $config = null): array
{
    $docx = Scratch::path('sized', '.docx');
    file_put_contents($docx, (new MarkdownToWord($markdown, $config))->toDocx($markdown));

    preg_match(
        '~<v:shape[^>]*style="width:([\d.]+)px; height:([\d.]+)px~',
        TemplateFactory::xmlOf($docx),
        $shape,
    );

    $rtf = (new MarkdownToWord($markdown, $config))->toRtf($markdown);
    preg_match('~\\\\picwgoal(\d+)~', $rtf, $goal);

    $odt = Scratch::path('sized', '.odt');
    file_put_contents($odt, (new MarkdownToWord($markdown, $config))->toOdt($markdown));

    $zip = new ZipArchive();
    $zip->open($odt);
    $content = (string) $zip->getFromName('content.xml');
    $zip->close();

    preg_match('~svg:width="([\d.]+)cm" svg:height="([\d.]+)cm~', $content, $frame);

    return [
        // The `.docx` states the size in pixels, and a pixel is a 96th of an inch.
        'docx' => (float) ($shape[1] ?? '0') * 72 / 96,
        // The `.rtf` states it in twips, of which there are twenty to a point.
        'rtf' => (int) ($goal[1] ?? '0') / 20,
        // The `.odt` states it in centimetres.
        'odt' => (float) ($frame[1] ?? '0') * 72 / 2.54,
    ];
}

it('writes a picture of 96 pixels at 96 pixels, and not at 96 points', function () {
    $directory = sizedPicture(96, 48);
    $markdown = '![A picture](picture.png)';

    $docx = Scratch::path('pixels', '.docx');
    file_put_contents($docx, (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
    ])))->toDocx($markdown));

    // The unit is in the file, and it is the unit the number is in. PHPWord labels
    // an image `pt` unless the style says otherwise, and a picture of 96 pixels
    // written as 96 points is a third too large.
    expect(TemplateFactory::xmlOf($docx))->toContain('width:96px; height:48px');
});

it('lays the same picture out at the same size in all three formats', function () {
    $directory = sizedPicture(96, 48);
    $markdown = '![A picture](picture.png)';

    $sizes = pictureSizesInPoints($markdown, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
    ]));

    // 96 pixels is 72 points, which is an inch.
    expect($sizes['docx'])->toEqualWithDelta(72.0, 0.01)
        ->and($sizes['rtf'])->toEqualWithDelta(72.0, 0.01)
        ->and($sizes['odt'])->toEqualWithDelta(72.0, 0.01);
});

it('scales a picture wider than the cap to the cap, in pixels', function () {
    $directory = sizedPicture(600, 300);
    $markdown = '![A picture](picture.png)';

    $docx = Scratch::path('capped', '.docx');
    file_put_contents($docx, (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
        'imageMaxWidth' => 2.5,
    ])))->toDocx($markdown));

    // 2.5cm is 94.49 pixels, which is 70.87 points.
    expect(TemplateFactory::xmlOf($docx))->toContain('width:94.488188976378px')
        ->and(pictureSizesInPoints($markdown, Configuration::create()->withOptions([
            'images' => 'embed',
            'imageBasePath' => $directory,
            'imageMaxWidth' => 2.5,
        ]))['docx'])->toEqualWithDelta(70.87, 0.01);
});
