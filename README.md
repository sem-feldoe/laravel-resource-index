# Simple service to build api response for resource index

Example

```php
use Atx\ResourceIndex\Contracts\ResourceIndex;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Index extends Controller
{
    public function __invoke(Request $request, ResourceIndex $service): JsonResponse
    {
        return $service->from(MyModel::class, MyModelResource::class)
            ->processRequest(
                $request,
                // Filterable
                [
                    'filterable_column',
                ],
                // Searchable
                [
                    'searchable_column',
                ],
                // Sortable
                [
                    'sortable_column',
                ]
            )
            ->response();
    }
}
```
