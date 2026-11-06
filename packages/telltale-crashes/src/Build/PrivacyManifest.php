<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleCrashes\Build;

use DOMDocument;
use DOMElement;
use RuntimeException;

final class PrivacyManifest
{
    public function install(string $buildPath): void
    {
        $destination = rtrim($buildPath, '/').'/NativePHP/PrivacyInfo.xcprivacy';
        if (! is_dir(dirname($destination))) {
            throw new RuntimeException('NativePHP iOS target directory is missing.');
        }
        $addition = $this->read(__DIR__.'/../../resources/PrivacyInfo.xcprivacy');
        $document = is_file($destination) ? $this->read($destination) : $addition;
        if ($document !== $addition) {
            $this->merge($this->root($document), $this->root($addition), $document);
        }
        $temporary = tempnam(dirname($destination), '.telltale-privacy-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot stage the privacy manifest.');
        }
        try {
            if ($document->save($temporary) === false || ! rename($temporary, $destination)) {
                throw new RuntimeException('Cannot install the privacy manifest.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function read(string $path): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->load($path, LIBXML_NONET | LIBXML_NOBLANKS)
                || $document->documentElement?->tagName !== 'plist'
                || $document->documentElement->firstElementChild?->tagName !== 'dict') {
                throw new RuntimeException('Privacy manifest must be an XML plist dictionary. Convert binary plists before building.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function root(DOMDocument $document): DOMElement
    {
        $root = $document->documentElement?->firstElementChild;
        if (! $root instanceof DOMElement || $root->tagName !== 'dict') {
            throw new RuntimeException('Privacy manifest dictionary is missing.');
        }

        return $root;
    }

    private function merge(DOMElement $target, DOMElement $addition, DOMDocument $document): void
    {
        if ($target->tagName === 'dict' && $addition->tagName === 'dict') {
            for ($key = $addition->firstElementChild; $key !== null; $key = $value->nextElementSibling) {
                $value = $key->nextElementSibling;
                if ($key->tagName !== 'key' || $value === null) {
                    throw new RuntimeException('Invalid privacy dictionary.');
                }
                $existing = $this->value($target, $key->textContent);
                if ($existing === null) {
                    $target->appendChild($document->importNode($key, true));
                    $target->appendChild($document->importNode($value, true));
                } else {
                    $this->merge($existing, $value, $document);
                }
            }

            return;
        }
        if ($target->tagName === 'array' && $addition->tagName === 'array') {
            foreach ($addition->childNodes as $item) {
                if (! $item instanceof DOMElement) {
                    continue;
                }
                $match = null;
                foreach ($target->childNodes as $candidate) {
                    if ($candidate instanceof DOMElement && $this->identity($candidate) === $this->identity($item)) {
                        $match = $candidate;
                        break;
                    }
                }
                if ($match !== null) {
                    $this->merge($match, $item, $document);
                } else {
                    $target->appendChild($document->importNode($item, true));
                }
            }

            return;
        }
        // Never downgrade another contributor's linked-data or tracking disclosure.
        if (in_array($target->tagName, ['true', 'false'], true) && in_array($addition->tagName, ['true', 'false'], true)) {
            if ($addition->tagName === 'true' && $target->tagName === 'false') {
                $parent = $target->parentNode;
                if ($parent === null) {
                    throw new RuntimeException('Detached privacy declaration.');
                }
                $parent->replaceChild($document->createElement('true'), $target);
            }

            return;
        }
        if ($target->tagName !== $addition->tagName || $target->textContent !== $addition->textContent) {
            throw new RuntimeException('Conflicting privacy declaration.');
        }
    }

    private function value(DOMElement $dictionary, string $name): ?DOMElement
    {
        foreach ($dictionary->childNodes as $key) {
            if ($key instanceof DOMElement && $key->tagName === 'key' && $key->textContent === $name) {
                return $key->nextElementSibling;
            }
        }

        return null;
    }

    private function identity(DOMElement $element): string
    {
        if ($element->tagName === 'dict') {
            foreach (['NSPrivacyCollectedDataType', 'NSPrivacyAccessedAPIType'] as $key) {
                if (($value = $this->value($element, $key)) !== null) {
                    return $key.':'.$value->textContent;
                }
            }
        }

        return $element->tagName.':'.$element->textContent;
    }
}
