<?php
declare(strict_types=1);

namespace DevDelivery;

use XMLReader;

final class JUnit
{
    public const MAX_BYTES = 2_097_152;
    public const MAX_CASES = 5000;

    public static function parse(string $xml): array
    {
        if (trim($xml) === '' || strlen($xml) > self::MAX_BYTES) {
            throw new Problem(422, 'junit_invalid_document');
        }
        $reader = new XMLReader();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (!$reader->XML($xml, null, LIBXML_NONET)) {
                throw new Problem(422, 'junit_invalid_document');
            }
            $cases = self::readCases($reader);
            if (libxml_get_last_error() !== false || !$cases) {
                throw new Problem(422, 'junit_invalid_document');
            }
            return $cases;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function key(string $class, string $name): string
    {
        return hash('sha256', json_encode([$class, $name], JSON_THROW_ON_ERROR));
    }

    private static function readCases(XMLReader $reader): array
    {
        $elements = [];
        $suitePath = [];
        $cases = [];
        $active = null;
        while ($reader->read()) {
            $depth = $reader->depth;
            if ($reader->nodeType === XMLReader::DOC_TYPE || $reader->nodeType === XMLReader::ENTITY_REF || $depth > 32) {
                throw new Problem(422, 'junit_unsupported_xml');
            }
            if ($reader->nodeType === XMLReader::ELEMENT) {
                $name = $reader->localName;
                $elements[$depth] = $name;
                $suite = in_array($name, ['testsuites', 'testsuite'], true);
                $suitePath[$depth] = $suite && ($depth === 0 || $suitePath[$depth - 1]);
                if ($depth === 0 && !$suite) {
                    throw new Problem(422, 'junit_invalid_root');
                }
                if ($name === 'testcase' && $depth > 0 && $suitePath[$depth - 1] && $elements[$depth - 1] === 'testsuite') {
                    if ($active !== null || count($cases) >= self::MAX_CASES) {
                        throw new Problem(422, 'junit_case_limit_or_nesting');
                    }
                    $active = self::entry($reader);
                    if ($reader->isEmptyElement) {
                        $cases[] = $active['result'];
                        $active = null;
                    }
                } elseif ($active !== null && $depth === $active['depth'] + 1 && in_array($name, ['failure', 'error', 'skipped'], true)) {
                    if ($active['result']['outcome'] !== 'passed') {
                        throw new Problem(422, 'junit_ambiguous_result');
                    }
                    $active['result']['outcome'] = $name === 'skipped' ? 'skipped' : 'failed';
                    $message = trim($reader->getAttribute('message') ?? '');
                    $active['result']['summary'] = mb_substr($message !== '' ? $message : $name, 0, 800, 'UTF-8');
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $active !== null
                && $depth === $active['depth'] && $reader->localName === 'testcase') {
                $cases[] = $active['result'];
                $active = null;
            }
        }
        return $cases;
    }

    private static function entry(XMLReader $reader): array
    {
        $class = $reader->getAttribute('classname') ?? '';
        $name = $reader->getAttribute('name');
        $time = $reader->getAttribute('time');
        if ($name === null || $name === '' || mb_strlen($name, 'UTF-8') > 240 || mb_strlen($class, 'UTF-8') > 240) {
            throw new Problem(422, 'junit_case_identity_invalid');
        }
        $duration = null;
        if ($time !== null) {
            if (!is_numeric($time) || (float) $time < 0 || (float) $time > 604800) {
                throw new Problem(422, 'junit_case_duration_invalid');
            }
            $duration = (int) round((float) $time * 1000);
        }
        return ['depth' => $reader->depth, 'result' => ['key' => self::key($class, $name), 'outcome' => 'passed',
            'duration_ms' => $duration, 'summary' => '']];
    }
}
