# Simple service to build api response for resource index

## About laravel resource index

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
Front usage example

```js
fetch('http://localhost/api/v1/endpoint?filter[filter1][]=test&filter[filter2]=true&sort=-created_at&search=test')
```


## Optional Wayfinder integration

Existing `from(Model::class, Resource::class)` calls remain supported. Applications
with a custom implementation or override of the ResourceIndex interface must also
make the resource argument nullable and optional to match the updated contract.
To expose the resource to Wayfinder, declare it once on the controller method:

```php
use Atx\ResourceIndex\Attributes\ResourceIndexResponse;

#[ResourceIndexResponse(MyModelResource::class)]
public function __invoke(Request $request, ResourceIndex $service): JsonResponse
{
    return $service->from(MyModel::class)
        ->processRequest($request)
        ->additional(['summary' => ['label' => 'Example']])
        ->response();
}
```

The attribute is declared on the controller method. Its resource is used both at
runtime and during generation.
Declaring both the attribute and the `resource` argument to `from()` throws a
`LogicException`, even when they refer to the same class. Omitting both also throws.
An explicit request can be passed as `from(MyModel::class, request: $request)`.

No additional registration is required when Wayfinder is installed. The integration
uses Ranger's route hook and Surveyor's resource analyzer. Wayfinder is optional:
normal HTTP responses continue working without the development dependency.

The generated response describes item fields from the JsonResource. Pagination
`meta` and `links` are optional records because pagination and additional data can
vary per request. Arbitrary fields added by `additional()` are preserved at runtime
but are not inferred. On attributed listings, `additional()` cannot set `data`,
which is reserved for the declared collection. Legacy calls retain their previous
`additional()` behavior. Global `JsonResource::withoutWrapping()` is supported.

The initial integration supports item JsonResources. Custom ResourceCollection
inference is rejected explicitly during generation instead of emitting a misleading
type. Accuracy of nested resources and conditional fields also depends on the
installed Wayfinder/Surveyor versions. No vendor patches are installed by this package.

## Tests

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

Core tests use an in-memory SQLite database. Wayfinder adapter tests are skipped
when the optional dependencies are absent.

## License

Laravel resource index is open-sourced software licensed under the [MIT license](LICENSE.md).
