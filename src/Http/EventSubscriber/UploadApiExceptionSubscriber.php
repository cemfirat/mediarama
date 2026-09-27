<?php

declare(strict_types=1);

namespace Mediarama\Http\EventSubscriber;

use Mediarama\Upload\Application\UploadProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class UploadApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (
            !$event->isMainRequest()
            || !str_starts_with($event->getRequest()->getPathInfo(), '/api/uploads')
        ) {
            return;
        }

        $error = $event->getThrowable();
        if (!$error instanceof UploadProblem) {
            return;
        }

        $payload = [
            'error' => $error->publicCode,
            'retryable' => $error->retryable,
        ];

        if ($error->failureStage !== null) {
            $payload['failure_stage'] = $error->failureStage->value;
        }

        foreach ($error->safeDetails as $key => $value) {
            $payload[$key] = $value;
        }

        $event->setResponse(new JsonResponse(
            $payload,
            $this->statusCode($error->publicCode),
        ));
    }

    private function statusCode(string $code): int
    {
        return match ($code) {
            'invalid_upload_request' => Response::HTTP_BAD_REQUEST,
            'upload_not_found' => Response::HTTP_NOT_FOUND,
            'upload_expired' => Response::HTTP_GONE,
            'upload_state_conflict',
            'upload_incomplete' => Response::HTTP_CONFLICT,
            'media_type_not_allowed' => Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            'upload_temporarily_unavailable' => Response::HTTP_SERVICE_UNAVAILABLE,
            'asset_size_invalid',
            'chunk_size_invalid',
            'chunk_size_mismatch',
            'chunk_checksum_mismatch',
            'invalid_media',
            'content_size_mismatch',
            'promoted_content_mismatch',
            'upload_quota_exceeded' => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_BAD_REQUEST,
        };
    }
}
