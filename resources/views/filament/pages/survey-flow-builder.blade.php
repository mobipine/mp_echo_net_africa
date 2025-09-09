<x-filament-panels::page>
    @push('scripts')
        @vite(['resources/js/app.js'])
    @endpush
    <div class="min-h-screen bg-" id="survey-flow-app"></div>

    <script>
        window.surveyId = {{ $surveyId }};
    </script>

    {{-- tailwindcdn --}}
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

<style>
    .fi-main {
        width: 100% !important;
        padding: 0 !important;
    }

    .max-w-7xl {
        max-width: 100% !important;
    }

    .min-h-screen {
        min-height: 88vh !important;
    }

    html,
    body,
    #app {
        margin: 0;
        height: 100%;
    }
    * {
        font-family: Poppins, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        
    }

    .vue-flow__minimap {
        transform: scale(75%);
        transform-origin: bottom right;
    }

    .dnd-flow {
        flex-direction: column;
        display: flex;
        height: 100%
    }

    .dnd-flow aside {
        border-bottom-left-radius: 0.5rem;
        border-top-left-radius: 0.5rem;
        color: #fff;
        font-weight: 700;
        border-right: 1px solid #eee;
        padding: 15px 10px;
        font-size: 12px;
        background: #f4f4f5;
        /* -webkit-box-shadow:0px 5px 10px 0px rgba(0,0,0,.3); */
        box-shadow: 0 2px 5px #0000004d
    }

    .dnd-flow aside .nodes>* {
        margin-bottom: 10px;
        cursor: grab;
        font-weight: 500;
        -webkit-box-shadow: 5px 5px 10px 2px rgba(0, 0, 0, .25);
        rounded: 15px;
        box-shadow: 5px 5px 10px 2px #00000040
    }


    .dnd-flow .vue-flow-wrapper {
        flex-grow: 1;
        height: 100%
    }

    @media screen and (min-width: 640px) {
        .dnd-flow {
            flex-direction: row
        }

        .dnd-flow aside {
            min-width: 200px;
        }
    }

    @media screen and (max-width: 639px) {
        .dnd-flow aside .nodes {
            display: flex;
            flex-direction: row;
            gap: 5px
        }
    }

    .dropzone-background {
        position: relative;
        height: 100%;
        width: 100%
    }

    .dropzone-background .overlay {
        position: absolute;
        top: 0;
        left: 0;
        height: 100%;
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1;
        pointer-events: none
    }
</style>
</x-filament-panels::page>