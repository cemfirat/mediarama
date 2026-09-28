<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Library;

use Mediarama\Collection\Application\LibraryFilterSmartCollectionRuleFactory;
use Mediarama\Collection\Application\SmartCollectionConfigurator;
use Mediarama\Collection\Application\SmartCollectionManagement;
use Mediarama\Collection\Application\SmartCollectionManagementResult;
use Mediarama\Collection\Application\SmartCollectionPublication;
use Mediarama\Collection\Application\SmartCollectionResolver;
use Mediarama\Collection\Application\SmartCollectionRuleFormFactory;
use Mediarama\Collection\Application\SmartCollectionUnavailableException;
use Mediarama\Http\Support\LibraryMediaSearchCriteriaFactory;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Mediarama\Security\Application\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class SmartCollectionController extends AbstractController
{
    private const PAGE_SIZE = 24;

    public function __construct(
        private readonly SmartCollectionManagement $management,
        private readonly SmartCollectionResolver $resolver,
        private readonly SmartCollectionConfigurator $configurator,
        private readonly SmartCollectionPublication $publication,
        private readonly SmartCollectionRuleFormFactory $ruleForm,
        private readonly LibraryFilterSmartCollectionRuleFactory $filterRule,
        private readonly LibraryMediaSearchCriteriaFactory $criteriaFactory,
        private readonly CurrentUser $currentUser,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/library/smart-collections', name: 'library_smart_collections', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->user();

        return $this->privateResponse($this->render(
            '@Mediarama/library/smart_collections/index.html.twig',
            [
                'collections' => $this->management->owned($user->id),
                'created' => $request->query->getBoolean('created'),
                'deleted' => $request->query->getBoolean('deleted'),
            ],
        ));
    }

    #[Route('/library/smart-collections/new', name: 'library_smart_collection_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->user();

        if ($request->isMethod('POST')) {
            $this->requireCsrf(
                'smart_collection_create',
                (string) $request->request->get('_csrf_token', ''),
            );

            try {
                $rule = $this->ruleForm->create(
                    $request->request->getString('group_operator', 'and'),
                    $request->request->all('field'),
                    $request->request->all('operator'),
                    $request->request->all('value'),
                    $request->request->all('secondary'),
                );
                $id = $this->management->create(
                    $user->id,
                    $request->request->getString('title'),
                    $this->nullableString($request->request->get('description')),
                    $rule,
                );

                return $this->redirectToRoute(
                    'library_smart_collection_show',
                    ['id' => $id->toRfc4122(), 'created' => 1],
                );
            } catch (\InvalidArgumentException $exception) {
                return $this->renderForm(
                    title: $request->request->getString('title'),
                    description: $this->nullableString($request->request->get('description')),
                    groupOperator: $request->request->getString('group_operator', 'and'),
                    rows: $this->requestRows($request),
                    error: $exception->getMessage(),
                    status: Response::HTTP_BAD_REQUEST,
                );
            }
        }

        return $this->renderForm(
            title: '',
            description: null,
            groupOperator: 'and',
            rows: $this->ruleForm->blankRows(),
        );
    }

    #[Route('/library/smart-collections/from-filter', name: 'library_smart_collection_from_filter', methods: ['POST'])]
    public function fromFilter(Request $request): Response
    {
        $user = $this->user();
        $this->requireCsrf(
            'smart_collection_from_filter',
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $criteria = $this->criteriaFactory->fromInput($request->request);
            $rule = $this->filterRule->create($criteria);
            $id = $this->management->create(
                $user->id,
                $request->request->getString('title'),
                null,
                $rule,
            );
        } catch (\InvalidArgumentException) {
            return $this->redirectToRoute('library_home', [
                'smart_error' => 'unsupported_filter',
            ]);
        }

        return $this->redirectToRoute(
            'library_smart_collection_show',
            ['id' => $id->toRfc4122(), 'created' => 1],
        );
    }

    #[Route(
        '/library/smart-collections/{id}',
        name: 'library_smart_collection_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET', 'POST'],
    )]
    public function show(string $id, Request $request): Response
    {
        $user = $this->user();
        $collectionId = $this->id($id);
        $collection = $this->owned($user->id, $collectionId);

        if ($request->isMethod('POST')) {
            $this->requireCsrf(
                'smart_collection_edit_'.$collectionId->toRfc4122(),
                (string) $request->request->get('_csrf_token', ''),
            );

            try {
                $rule = $this->ruleForm->create(
                    $request->request->getString('group_operator', 'and'),
                    $request->request->all('field'),
                    $request->request->all('operator'),
                    $request->request->all('value'),
                    $request->request->all('secondary'),
                );
                $this->management->update(
                    $user->id,
                    $collectionId,
                    $request->request->getString('title'),
                    $this->nullableString($request->request->get('description')),
                    $rule,
                );

                return $this->redirectToRoute(
                    'library_smart_collection_show',
                    ['id' => $collectionId->toRfc4122(), 'saved' => 1],
                );
            } catch (\InvalidArgumentException $exception) {
                return $this->renderDetail(
                    $collection,
                    $user->id,
                    $request,
                    error: $exception->getMessage(),
                    overrideTitle: $request->request->getString('title'),
                    overrideDescription: $this->nullableString($request->request->get('description')),
                    overrideGroup: $request->request->getString('group_operator', 'and'),
                    overrideRows: $this->requestRows($request),
                    status: Response::HTTP_BAD_REQUEST,
                );
            }
        }

        return $this->renderDetail($collection, $user->id, $request);
    }

    #[Route(
        '/library/smart-collections/{id}/publish',
        name: 'library_smart_collection_publish',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function publish(string $id, Request $request): Response
    {
        $user = $this->user();
        $collectionId = $this->id($id);
        $this->requireCsrf(
            'smart_collection_publish_'.$collectionId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        $policy = SearchIndexPolicy::tryFrom(
            $request->request->getString('search_index_policy', 'noindex'),
        );
        if (
            $policy === null
            || $policy === SearchIndexPolicy::Inherit
        ) {
            return $this->renderDetail(
                $this->owned($user->id, $collectionId),
                $user->id,
                $request,
                error: 'Choose an explicit index or noindex policy before publishing.',
                status: Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $coverMediaId = $this->nullableId(
                $request->request->getString('cover_media_id', ''),
            );
            $this->publication->publish(
                $user->id,
                $collectionId,
                $policy,
                $coverMediaId,
            );
        } catch (SmartCollectionUnavailableException) {
            throw $this->createNotFoundException();
        } catch (\InvalidArgumentException $exception) {
            return $this->renderDetail(
                $this->owned($user->id, $collectionId),
                $user->id,
                $request,
                error: $exception->getMessage(),
                status: Response::HTTP_BAD_REQUEST,
            );
        }

        return $this->redirectToRoute(
            'library_smart_collection_show',
            ['id' => $collectionId->toRfc4122(), 'published' => 1],
        );
    }

    #[Route(
        '/library/smart-collections/{id}/unpublish',
        name: 'library_smart_collection_unpublish',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function unpublish(string $id, Request $request): Response
    {
        $user = $this->user();
        $collectionId = $this->id($id);
        $this->requireCsrf(
            'smart_collection_unpublish_'.$collectionId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $this->publication->unpublish($user->id, $collectionId);
        } catch (SmartCollectionUnavailableException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute(
            'library_smart_collection_show',
            ['id' => $collectionId->toRfc4122(), 'unpublished' => 1],
        );
    }

    #[Route(
        '/library/smart-collections/{id}/delete',
        name: 'library_smart_collection_delete',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function delete(string $id, Request $request): Response
    {
        $user = $this->user();
        $collectionId = $this->id($id);
        $this->requireCsrf(
            'smart_collection_delete_'.$collectionId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $this->management->delete($user->id, $collectionId);
        } catch (SmartCollectionUnavailableException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute(
            'library_smart_collections',
            ['deleted' => 1],
        );
    }

    #[Route(
        '/library/smart-collections/{id}/manual',
        name: 'library_smart_collection_manual',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function convertManual(string $id, Request $request): Response
    {
        $user = $this->user();
        $collectionId = $this->id($id);
        $this->requireCsrf(
            'smart_collection_manual_'.$collectionId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $this->configurator->configureManual($user->id, $collectionId);
        } catch (SmartCollectionUnavailableException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('library_home', [
            'smart_error' => 'converted_manual',
        ]);
    }

    private function renderForm(
        string $title,
        ?string $description,
        string $groupOperator,
        array $rows,
        ?string $error = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $response = $this->render(
            '@Mediarama/library/smart_collections/form.html.twig',
            [
                'collection_title' => $title,
                'collection_description' => $description,
                'group_operator' => $groupOperator,
                'rows' => $this->padRows($rows),
                'error' => $error,
                'csrf_token' => $this->csrf->getToken('smart_collection_create')->getValue(),
            ],
            new Response(status: $status),
        );

        return $this->privateResponse($response);
    }

    private function renderDetail(
        SmartCollectionManagementResult $collection,
        Uuid $actorId,
        Request $request,
        ?string $error = null,
        ?string $overrideTitle = null,
        ?string $overrideDescription = null,
        ?string $overrideGroup = null,
        ?array $overrideRows = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $page = max($request->query->getInt('page', 1), 1);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $count = $this->resolver->count($actorId, $collection->id);
        $items = $this->resolver->resolve(
            $actorId,
            $collection->id,
            self::PAGE_SIZE,
            $offset,
        );
        $editableRows = $overrideRows ?? $this->ruleForm->editableRows($collection->rule);

        $response = $this->render(
            '@Mediarama/library/smart_collections/show.html.twig',
            [
                'collection' => $collection,
                'collection_title' => $overrideTitle ?? $collection->title,
                'collection_description' => $overrideDescription ?? $collection->description,
                'group_operator' => $overrideGroup ?? (string) $collection->rule->payload()['op'],
                'rows' => $editableRows !== null ? $this->padRows($editableRows) : null,
                'items' => $items,
                'count' => $count,
                'page' => $page,
                'page_size' => self::PAGE_SIZE,
                'error' => $error,
                'saved' => $request->query->getBoolean('saved'),
                'created' => $request->query->getBoolean('created'),
                'published' => $request->query->getBoolean('published'),
                'unpublished' => $request->query->getBoolean('unpublished'),
                'edit_csrf_token' => $this->csrf
                    ->getToken('smart_collection_edit_'.$collection->id->toRfc4122())
                    ->getValue(),
                'delete_csrf_token' => $this->csrf
                    ->getToken('smart_collection_delete_'.$collection->id->toRfc4122())
                    ->getValue(),
                'manual_csrf_token' => $this->csrf
                    ->getToken('smart_collection_manual_'.$collection->id->toRfc4122())
                    ->getValue(),
                'publish_csrf_token' => $this->csrf
                    ->getToken('smart_collection_publish_'.$collection->id->toRfc4122())
                    ->getValue(),
                'unpublish_csrf_token' => $this->csrf
                    ->getToken('smart_collection_unpublish_'.$collection->id->toRfc4122())
                    ->getValue(),
            ],
            new Response(status: $status),
        );

        return $this->privateResponse($response);
    }

    private function owned(
        Uuid $ownerId,
        Uuid $collectionId,
    ): SmartCollectionManagementResult {
        $collection = $this->management->getOwned($ownerId, $collectionId);

        if ($collection === null) {
            throw $this->createNotFoundException();
        }

        return $collection;
    }

    private function id(string $value): Uuid
    {
        try {
            return Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
    }

    private function user(): \Mediarama\Security\Application\AuthenticatedUser
    {
        try {
            return $this->currentUser->requireUser();
        } catch (\DomainException) {
            throw $this->createAccessDeniedException('Authentication is required.');
        }
    }

    private function requireCsrf(string $id, string $value): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken($id, $value))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /** @return list<array{field:string,operator:string,value:string,secondary:string}> */
    private function requestRows(Request $request): array
    {
        $fields = $request->request->all('field');
        $operators = $request->request->all('operator');
        $values = $request->request->all('value');
        $secondary = $request->request->all('secondary');
        $count = max(
            count($fields),
            count($operators),
            count($values),
            count($secondary),
        );

        $rows = [];
        for ($index = 0; $index < $count; ++$index) {
            $rows[] = [
                'field' => trim((string) ($fields[$index] ?? '')),
                'operator' => trim((string) ($operators[$index] ?? '')),
                'value' => trim((string) ($values[$index] ?? '')),
                'secondary' => trim((string) ($secondary[$index] ?? '')),
            ];
        }

        return $rows;
    }

    /** @param list<array{field:string,operator:string,value:string,secondary:string}> $rows */
    private function padRows(array $rows): array
    {
        while (count($rows) < 6) {
            $rows[] = [
                'field' => '',
                'operator' => '',
                'value' => '',
                'secondary' => '',
            ];
        }

        return $rows;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableId(string $value): ?Uuid
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException(
                'Cover MediaAsset must be a valid UUID.',
            );
        }
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
