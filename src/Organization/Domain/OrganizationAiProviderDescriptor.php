<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

final readonly class OrganizationAiProviderDescriptor
{
    /**
     * @param list<OrganizationAiCapability> $capabilities
     */
    public function __construct(
        public string $key,
        public OrganizationProducerKind $producerKind,
        public string $providerName,
        public string $modelName,
        public ?string $modelVersion,
        public array $capabilities,
        public ?string $privacyNote = null,
        public ?string $retentionNote = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/D', $key) !== 1) {
            throw new \InvalidArgumentException(
                'Organization AI provider key is invalid.',
            );
        }

        if ($producerKind === OrganizationProducerKind::Metadata) {
            throw new \InvalidArgumentException(
                'Organization AI providers must be local or external AI producers.',
            );
        }

        self::text($providerName, 'provider name', 160);
        self::text($modelName, 'model name', 255);
        self::nullableText($modelVersion, 'model version', 255);
        self::nullableText($privacyNote, 'privacy note', 2000);
        self::nullableText($retentionNote, 'retention note', 2000);

        if ($capabilities === []) {
            throw new \InvalidArgumentException(
                'Organization AI provider must advertise at least one capability.',
            );
        }

        $seen = [];
        foreach ($capabilities as $capability) {
            if (!$capability instanceof OrganizationAiCapability) {
                throw new \InvalidArgumentException(
                    'Organization AI provider capabilities must use OrganizationAiCapability values.',
                );
            }

            if (isset($seen[$capability->value])) {
                throw new \InvalidArgumentException(
                    'Organization AI provider capabilities must not contain duplicates.',
                );
            }

            $seen[$capability->value] = true;
        }

        $hasLocalInference = isset(
            $seen[OrganizationAiCapability::LocalInference->value],
        );

        if (
            $producerKind === OrganizationProducerKind::AiExternal
            && $hasLocalInference
        ) {
            throw new \InvalidArgumentException(
                'External AI provider cannot advertise local inference.',
            );
        }

        if (
            $producerKind === OrganizationProducerKind::AiLocal
            && !$hasLocalInference
        ) {
            throw new \InvalidArgumentException(
                'Local AI provider must advertise local inference.',
            );
        }
    }

    public function supports(OrganizationAiCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function producer(): OrganizationProducer
    {
        return match ($this->producerKind) {
            OrganizationProducerKind::AiExternal => OrganizationProducer::aiExternal(
                $this->providerName,
                $this->modelName,
                $this->modelVersion,
            ),
            OrganizationProducerKind::AiLocal => OrganizationProducer::aiLocal(
                $this->providerName,
                $this->modelName,
                $this->modelVersion,
            ),
            OrganizationProducerKind::Metadata => throw new \LogicException(
                'Metadata producer cannot describe an AI provider.',
            ),
        };
    }

    private static function text(
        string $value,
        string $label,
        int $maximumLength,
    ): void {
        $trimmed = trim($value);
        $length = iconv_strlen($trimmed, 'UTF-8');

        if (
            $value !== $trimmed
            || $trimmed === ''
            || $length === false
            || $length > $maximumLength
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Organization AI %s must contain 1-%d characters.',
                $label,
                $maximumLength,
            ));
        }
    }

    private static function nullableText(
        ?string $value,
        string $label,
        int $maximumLength,
    ): void {
        if ($value === null) {
            return;
        }

        self::text($value, $label, $maximumLength);
    }
}
