<div>
    <!-- The whole future lies in uncertainty: live immediately. - Seneca -->
</div>
@props(['name', 'title', 'description' => null])
<x-ui.dialog :name="$name" :title="$title" :description="$description">
    <div class="space-y-4 p-5">
        {{ $slot }}
    </div>
</x-ui.dialog>
