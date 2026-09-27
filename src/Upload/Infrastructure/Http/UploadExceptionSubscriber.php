<?php

declare(strict_types=1);

namespace Mediarama\Upload\Infrastructure\Http;

use Mediarama\Upload\Application\UploadQuotaExceeded;
use Mediarama\Upload\Domain\UploadFailureCode;
use Mediarama\Upload\Domain\UploadProblem;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 20)]
final readonly class UploadExceptionSubscriber
{
    public function __invoke(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();

        if ($error instanceof UploadQuotaExceeded) {
            $event->setResponse(new JsonResponse(
                [
                    'error' => 'upload_quota_exceeded',
                    'limit_bytes' => $error->limitBytes,
                    'committed_bytes' => $error->committedBytes,
                    'reserved_bytes' => $error->reservedBytes,
                    'requested_bytes' => $error->requestedBytes,
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Cache-Control' => 'no-store'],
            ));

            return;
        }

        if (!$error instanceof UploadProblem) {
            return;
        }

        $payload = [
            'error' => $error->errorCode,
            'message' => $error->publicMessage,
            'retryable' => $error->retryable,
        ];

        if ($error->stage() !== null) {
            $payload['stage'] = $error->stage()->value;
        }

        $event->setResponse(new JsonResponse(
            $payload,
            $this->statusCode($error),
            ['Cache-Control' => 'no-store'],
        ));
    }

    private function statusCode(UploadProblem $problem): int
    {
        if ($problem->failureCode !== null) {
            return match ($problem->failureCode) {
                UploadFailureCode::ChunksIncomplete => Response::HTTP_CONFLICT,
                UploadFailureCode::ChunkStorageUnavailable,
                UploadFailureCode::AssemblyStorageUnavailable,
                UploadFailureCode::InspectionUnavailable,
                UploadFailureCode::FinalizationStorageUnavailable,
                UploadFailureCode::FinalizationInterrupted => Response::HTTP_SERVICE_UNAVAILABLE,
                UploadFailureCode::FinalizationSourceMissing => Response::HTTP_GONE,
                UploadFailureCode::IntegrityMismatch => Response::HTTP_INTERNAL_SERVER_ERROR,
                UploadFailureCode::ChunkSizeInvalid,
                UploadFailureCode::ChunkSizeMismatch,
                UploadFailureCode::ChunkChecksumMismatch,
                UploadFailureCode::MediaTypeNotAllowed,
                UploadFailureCode::MediaInvalid,
                UploadFailureCode::MediaSizeMismatch => Response::HTTP_UNPROCESSABLE_ENTITY,
            };
        }

        return match ($problem->errorCode) {
            'upload_not_found' => Response::HTTP_NOT_FOUND,
            'upload_expired' => Response::HTTP_GONE,
            'upload_invalid_state' => Response::HTTP_CONFLICT,
            'upload_destination_forbidden' => Response::HTTP_FORBIDDEN,
            'upload_asset_size_invalid' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'upload_chunk_metadata_invalid' => Response::HTTP_BAD_REQUEST,
            'upload_abandon_unavailable' => Response::HTTP_SERVICE_UNAVAILABLE,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }
}
