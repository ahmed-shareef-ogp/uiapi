<?php

namespace App\Http\Controllers;

use App\Services\FileUploadService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class GenericApiController extends Controller
{
    /**
     * Display a listing of the model's resources.
     *
     * @param  string  $model
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, $model)
    {

        $requestParams = array_change_key_case($request->all(), CASE_LOWER);
        $wrap = strtolower((string) ($requestParams['wrap'] ?? ''));

        $modelClass = 'App\\Models\\'.ucfirst(strtolower($model));
        if (! class_exists($modelClass)) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        $query = $modelClass::query()
            ->selectColumns($requestParams['columns'] ?? null)
            ->search($requestParams['search'] ?? null)
            ->searchAll($requestParams['searchall'] ?? null)
            ->withoutRow($requestParams['withoutrow'] ?? null)
            ->filter($requestParams['filter'] ?? null)
            ->withRelations($requestParams['with'] ?? null)
            ->withPivotRelations($requestParams['pivot'] ?? null)
            ->sort($requestParams['sort'] ?? null);

        $perPage = isset($requestParams['per_page']) ? (int) $requestParams['per_page'] : null;
        $page = isset($requestParams['page']) ? (int) $requestParams['page'] : null;
        $records = $modelClass::paginateFromRequest(
            $query,
            $requestParams['pagination'] ?? null,
            $perPage,
            $page
        );

        // If paginated, reshape payload to include desired pagination fields
        if (($requestParams['pagination'] ?? null) !== 'off' && $records instanceof Paginator) {

            // Compute total and last_page independently to avoid LengthAware paginator
            try {
                $total = (int) (clone $query)->toBase()->getCountForPagination();
            } catch (\Throwable $e) {
                $total = (int) (clone $query)->count();
            }

            $effectivePerPage = $perPage ?? $records->perPage();
            $lastPage = (int) max(1, (int) ceil(($total > 0 ? $total : 1) / max(1, (int) $effectivePerPage)));

            return response()->json([
                'data' => $records->items(),
                'pagination' => [
                    'current_page' => $records->currentPage(),
                    'first_page' => 1,
                    'last_page' => $lastPage,
                    'per_page' => $effectivePerPage,
                    'total' => $total,
                    // 'next_page_url' => $records->nextPageUrl(),
                    // 'prev_page_url' => $records->previousPageUrl(),
                ],
            ]);
        }

        // When wrap=data is requested, always wrap non-paginated results
        if ($wrap === 'data') {
            return response()->json(['data' => $records]);
        }

        return response()->json($records);
    }

    /**
     * Display the specified model resource.
     *
     * @param  string  $model
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $model, $id)
    {
        $requestParams = array_change_key_case($request->all(), CASE_LOWER);

        $modelClass = 'App\\Models\\'.ucfirst(strtolower($model));
        if (! class_exists($modelClass)) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        try {
            $query = $modelClass::query()
                ->selectColumns($requestParams['columns'] ?? null)
                ->withRelations($requestParams['with'] ?? null)
                ->withPivotRelations($requestParams['pivot'] ?? null);

            $record = $query->findOrFail($id);

            return response()->json($record);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Record not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request, string $model)
    {
        $modelClass = 'App\\Models\\'.ucfirst($model);

        if (! class_exists($modelClass)) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        $modelClass::validate($request);

        $record = $modelClass::createFromRequest($request, $request->user() ?: null);

        // Need to confirm if fileupload works
        app(FileUploadService::class)->handle(
            $request,
            $record,
            $model,
            $request->user() ?? null
        );

        return response()->json($record, 201);
    }

    /**
     * Update the specified model resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, string $model, int $id)
    {
        $modelClass = 'App\\Models\\'.ucfirst($model);

        if (! class_exists($modelClass)) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        $record = $modelClass::findOrFail($id);

        // Validate
        $modelClass::validate($request, $record);

        // Update
        $record = $record->updateFromRequest($request, $request->user());

        return response()->json($record, 200);
    }

    /**
     * Remove the specified model resource.
     *
     * @param  string  $model
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($model, $id)
    {
        $modelClass = 'App\\Models\\'.ucfirst($model);
        if (! class_exists($modelClass)) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        try {
            $record = $modelClass::findOrFail($id);
            $record->delete();

            return response()->json(['message' => 'Record deleted successfully'], 204);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Record not found'], 404);
        }
    }

    public function uploadFile(Request $request)
    {
        try {
            Log::info('Upload File Request: '.json_encode($request->all()));

            // Validate the request
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|max:10240', // Max 10MB
                'uploadables' => 'required|array|min:1',
                'uploadables.*.uploadable_type' => 'required|string',
                'uploadables.*.uploadable_id' => 'required|integer',
                'category_id' => 'nullable|integer|exists:document_categories,id',
                'document_date' => 'nullable|date',
                'document_reference' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'direction' => 'nullable|string',
                'from_type' => 'nullable|string',
                'from_id' => 'nullable|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Check if the file exists in the request
            if (! $request->hasFile('file')) {
                return response()->json(['error' => 'No file uploaded'], 400);
            }

            $file = $request->file('file');

            // Check if file is valid
            if (! $file->isValid()) {
                return response()->json(['error' => 'Invalid file upload'], 400);
            }

            // Store the file
            try {
                $path = $file->store('uploads/files', 'public');
                if (! $path) {
                    throw new \Exception('Failed to store file');
                }
            } catch (\Exception $e) {
                Log::error('File storage failed: '.$e->getMessage());

                return response()->json(['error' => 'Failed to store file'], 500);
            }

            $sizeInKB = round($file->getSize() / 1024, 2);

            // Handle direction mapping with error handling
            $direction = null;
            try {
                $direction = match ($request->input('direction')) {
                    'ލިބުނު' => 'incoming',
                    'ފޮނުވި' => 'outgoing',
                    default => null,
                };
            } catch (\Exception $e) {
                Log::warning('Direction mapping failed: '.$e->getMessage());
            }

            // Handle from_type mapping with error handling
            $fromType = null;
            try {
                $fromType = match ($request->input('from_type')) {
                    'މުއައްސަސާއެއް' => 'App\\Models\\Organisation',
                    'ފަރުދެއް' => 'App\\Models\\Person',
                    default => null,
                };
            } catch (\Exception $e) {
                Log::warning('From type mapping failed: '.$e->getMessage());
            }

            // Create upload record
            try {
                $upload = Upload::create([
                    'name' => $file->getClientOriginalName(),
                    'path' => $path, // Store relative path, not full URL
                    'category_id' => $request->input('category_id', 6),
                    'size' => $sizeInKB,
                    'document_date' => $request->input('document_date'),
                    'document_reference' => $request->input('document_reference'),
                    'description' => $request->input('description'),
                    'direction' => $direction,
                    'from_type' => $fromType,
                    'from_id' => $request->input('from_id'),
                    'uploaded_by' => Auth::user()?->username ?? 'system',
                ]);
            } catch (\Exception $e) {
                Log::error('Upload record creation failed: '.$e->getMessage());
                // Clean up the stored file if database creation fails
                Storage::disk('public')->delete($path);

                return response()->json(['error' => 'Failed to create upload record'], 500);
            }

            // Attach polymorphic relations
            $uploadables = $request->input('uploadables', []);
            $attachmentErrors = [];

            Log::info('Uploadables: '.json_encode($uploadables));

            foreach ($uploadables as $index => $uploadable) {
                try {
                    if (! isset($uploadable['uploadable_type']) || ! isset($uploadable['uploadable_id'])) {
                        $attachmentErrors[] = "Missing uploadable_type or uploadable_id at index {$index}";

                        continue;
                    }

                    $uploadableType = $uploadable['uploadable_type'];
                    $uploadableId = $uploadable['uploadable_id'];

                    Log::info('Processing uploadable_type: '.$uploadableType);

                    // Validate the uploadable exists before attaching
                    $validTypes = [
                        'App\Models\Kase' => 'cases',
                        'App\Models\Person' => 'people',
                        'App\Models\Verdict' => 'verdicts',
                    ];

                    if (! array_key_exists($uploadableType, $validTypes)) {
                        $attachmentErrors[] = "Invalid uploadable_type: {$uploadableType} at index {$index}";

                        continue;
                    }

                    // Check if the related model exists
                    $modelClass = $uploadableType;
                    if (! class_exists($modelClass)) {
                        $attachmentErrors[] = "Model class {$modelClass} does not exist at index {$index}";

                        continue;
                    }

                    $relatedModel = $modelClass::find($uploadableId);
                    if (! $relatedModel) {
                        $attachmentErrors[] = "Related model {$uploadableType} with ID {$uploadableId} not found at index {$index}";

                        continue;
                    }

                    // Attach the relationship
                    switch ($uploadableType) {
                        case 'App\Models\Kase':
                            $upload->cases()->attach($uploadableId);
                            break;
                        case 'App\Models\Person':
                            $upload->people()->attach($uploadableId);
                            break;
                        case 'App\Models\Verdict':
                            $upload->verdicts()->attach($uploadableId);
                            break;
                    }

                } catch (\Exception $e) {
                    Log::error("Attachment failed for uploadable at index {$index}: ".$e->getMessage());
                    $attachmentErrors[] = "Failed to attach uploadable at index {$index}: ".$e->getMessage();
                }
            }

            // If there were attachment errors but upload was created, log them but don't fail
            if (! empty($attachmentErrors)) {
                Log::warning('Some attachments failed: '.json_encode($attachmentErrors));
            }

            // Load the upload with its relationships for the response
            $upload->load(['cases', 'people', 'verdicts']);

            $response = [
                'upload' => $upload,
                'message' => 'File uploaded successfully',
            ];

            if (! empty($attachmentErrors)) {
                $response['warnings'] = $attachmentErrors;
            }

            return response()->json($response, 201);

        } catch (\Exception $e) {
            Log::error('Upload file failed: '.$e->getMessage().' Trace: '.$e->getTraceAsString());

            return response()->json([
                'error' => 'An unexpected error occurred during file upload',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    // public function uploadFile(Request $request)
    // {
    //     Log::info('Upload File Request: ' . json_encode($request->all()));
    //     // Debug: Check if the file exists in the request
    //     if (!$request->hasFile('file')) {
    //         return response()->json(['error' => 'No file uploaded'], 400);
    //     }

    //     $uploadableType = $request->input('uploadable_type');
    //     $resolvedId = $request->input('uploadable_id');

    //     $file = $request->file('file');
    //     $path = $file->store("uploads/files", 'public');
    //     $sizeInKB = round($file->getSize() / 1024, 2);

    //     $direction = match ($request->input('direction')) {
    //         "ލިބުނު" => 'incoming',
    //         "ފޮނުވި" => 'outgoing',
    //         default => null,
    //     };

    //     $fromType = match ($request->input('from_type')) {
    //         "މުއައްސަސާއެއް" => 'App\\Models\\Organisation',
    //         "ފަރުދެއް" => 'App\\Models\\Person',
    //         default => null,
    //     };

    //     $upload = Upload::create([
    //         // 'uploadable_type' => $uploadableType,
    //         // 'uploadable_id' => $resolvedId,
    //         'name' => $file->getClientOriginalName(),
    //         'path' => asset('storage/' . $path),
    //         'category_id' => $request->input('category_id', 6),
    //         'size' => $sizeInKB,
    //         'document_date' => $request->input('document_date'),
    //         'document_reference' => $request->input('document_reference', null),
    //         'description' => $request->input('description', null),
    //         'direction' => $direction,
    //         'from_type' => $fromType,
    //         'from_id' => $request->input('from_id', null),
    //         'uploaded_by' => Auth::user()?->username ?? 'system',
    //     ]);

    //     // Attach polymorphic relations (many)
    //     $uploadables = $request->input('uploadables', []);

    //     Log::info('Uploadables: ' . json_encode($uploadables));
    //     foreach ($uploadables as $uploadable) {
    //         Log::info('uploadable_type: ' . json_encode($uploadable['uploadable_type']));
    //         $uploadableType = $uploadable['uploadable_type']; // e.g. 'case' or 'person'
    //         $uploadableId = $uploadable['uploadable_id'];

    //         if ($uploadableType === 'App\Models\Kase') {
    //             $upload->cases()->attach($uploadableId);
    //         } elseif ($uploadableType === 'App\Models\Person') {
    //             $upload->people()->attach($uploadableId);
    //         } elseif ($uploadableType === 'App\Models\Verdict') {
    //             $upload->verdicts()->attach($uploadableId);
    //         }
    //     }

    //     return response()->json($upload, 201);
    // }

}
