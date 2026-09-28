<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

enum OrganizationAiCapability: string
{
    case TextReasoning = 'text_reasoning';
    case ImageUnderstanding = 'image_understanding';
    case Embeddings = 'embeddings';
    case BatchAnalysis = 'batch_analysis';
    case LocalInference = 'local_inference';
}
