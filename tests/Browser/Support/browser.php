<?php

use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverSelect;
use Laravel\Dusk\Browser;

/**
 * The plain text boxes of a form: the app's inputs often carry no `type` attribute at all.
 */
function duskTextInputs(): string
{
    return 'form input:not([type]), form input[type=text]';
}

function duskXpathLiteral(string $text): string
{
    return str_contains($text, '"') ? "'".$text."'" : '"'.$text.'"';
}

/**
 * The visible innermost elements whose whole text is the given text (a button, a tab, a link), waiting for the first one.
 *
 * @return list<RemoteWebElement>
 */
function duskElementsWithText(Browser $browser, string $text, int $seconds = 15): array
{
    $literal = duskXpathLiteral($text);
    $xpath = "//*[not(self::script) and not(self::style) and normalize-space(.)={$literal} and not(.//*[normalize-space(.)={$literal}])]";
    $deadline = microtime(true) + $seconds;

    do {
        $visible = array_values(array_filter(
            $browser->driver->findElements(WebDriverBy::xpath($xpath)),
            fn (RemoteWebElement $element): bool => $element->isDisplayed(),
        ));

        if ($visible !== []) {
            return $visible;
        }

        usleep(250_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("No visible element with the text [{$text}] appeared.");
}

function duskClickText(Browser $browser, string $text, int $index = 0): void
{
    $element = duskElementsWithText($browser, $text)[$index];
    $browser->driver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$element]);

    try {
        $element->click();
    } catch (WebDriverException) {
        $browser->driver->executeScript('arguments[0].click();', [$element]);
    }
}

/**
 * @return list<RemoteWebElement>
 */
function duskFields(Browser $browser, string $css, int $seconds = 15): array
{
    $deadline = microtime(true) + $seconds;

    do {
        $found = $browser->driver->findElements(WebDriverBy::cssSelector($css));

        if ($found !== []) {
            return $found;
        }

        usleep(250_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("Nothing matched [{$css}].");
}

function duskTypeInto(Browser $browser, string $css, int $index, string $text): void
{
    $field = duskFields($browser, $css)[$index];
    $browser->driver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$field]);
    $field->clear();
    $field->sendKeys($text);
}

function duskSelectOption(Browser $browser, string $css, int $index, string $value, int $seconds = 15): void
{
    $deadline = microtime(true) + $seconds;

    // The options of a list are often loaded after the form opens, so wait until the wanted one is there.
    do {
        $field = duskFields($browser, $css)[$index] ?? null;

        if ($field !== null) {
            $browser->driver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$field]);

            try {
                (new WebDriverSelect($field))->selectByValue($value);

                return;
            } catch (NoSuchElementException) {
                usleep(300_000);
            }
        }
    } while (microtime(true) < $deadline);

    throw new RuntimeException("No option [{$value}] appeared in the list [{$css}] #{$index}.");
}

/**
 * Calls one of the app's own JSON endpoints from inside the signed-in browser page (same session and CSRF cookie the pages
 * use) and gives back the status and decoded body.
 *
 * @param  array<string, mixed>  $payload
 * @return array{status: int, body: array<string, mixed>}
 */
function duskRequest(Browser $browser, string $method, string $url, array $payload = []): array
{
    $result = $browser->driver->executeAsyncScript(
        <<<'JS'
        const done = arguments[arguments.length - 1];
        const token = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) || '').split('=')[1] || '');
        fetch(arguments[1], {
            method: arguments[0],
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest'},
            body: arguments[0] === 'GET' ? undefined : arguments[2],
        }).then((response) => response.text().then((text) => done(JSON.stringify({status: response.status, body: text}))));
        JS,
        [$method, $url, json_encode($payload)],
    );

    $decoded = json_decode((string) $result, true);

    return ['status' => (int) $decoded['status'], 'body' => (array) json_decode((string) $decoded['body'], true)];
}
