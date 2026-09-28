<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

final readonly class OrganizationProducer
{
    private function __construct(
        public OrganizationProducerKind $kind,
        public ?string $providerName,
        public ?string $modelName,
        public ?string $modelVersion,
    ) {
    }

    public static function metadata(): self
    {
        return new self(
            OrganizationProducerKind::Metadata,
            null,
            null,
            null,
        );
    }

    public static function aiExternal(
        string $providerName,
        string $modelName,
        ?string $modelVersion = null,
    ): self {
        return self::ai(
            OrganizationProducerKind::AiExternal,
            $providerName,
            $modelName,
            $modelVersion,
        );
    }

    public static function aiLocal(
        string $providerName,
        string $modelName,
        ?string $modelVersion = null,
    ): self {
        return self::ai(
            OrganizationProducerKind::AiLocal,
            $providerName,
            $modelName,
            $modelVersion,
        );
    }

    public static function fromPersisted(
        OrganizationProducerKind $kind,
        ?string $providerName,
        ?string $modelName,
        ?string $modelVersion,
    ): self {
        if ($kind === OrganizationProducerKind::Metadata) {
            if (
                $providerName !== null
                || $modelName !== null
                || $modelVersion !== null
            ) {
                throw new \RuntimeException(
                    'Persisted metadata producer unexpectedly carries AI provider state.',
                );
            }

            return self::metadata();
        }

        if ($providerName === null || $modelName === null) {
            throw new \RuntimeException(
                'Persisted AI producer is missing provider/model audit identity.',
            );
        }

        return self::ai(
            $kind,
            $providerName,
            $modelName,
            $modelVersion,
        );
    }

    private static function ai(
        OrganizationProducerKind $kind,
        string $providerName,
        string $modelName,
        ?string $modelVersion,
    ): self {
        if ($kind === OrganizationProducerKind::Metadata) {
            throw new \InvalidArgumentException(
                'Metadata producer does not accept AI provider identity.',
            );
        }

        $providerName = self::text($providerName, 'provider name', 160);
        $modelName = self::text($modelName, 'model name', 255);
        $modelVersion = self::nullableText(
            $modelVersion,
            'model version',
            255,
        );

        return new self(
            $kind,
            $providerName,
            $modelName,
            $modelVersion,
        );
    }

    private static function text(
        string $value,
        string $label,
        int $maximumLength,
    ): string {
        $value = trim($value);
        $length = iconv_strlen($value, 'UTF-8');

        if (
            $value === ''
            || $length === false
            || $length > $maximumLength
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Organization %s must contain 1-%d characters.',
                $label,
                $maximumLength,
            ));
        }

        return $value;
    }

    private static function nullableText(
        ?string $value,
        string $label,
        int $maximumLength,
    ): ?string {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : self::text($value, $label, $maximumLength);
    }
}
