<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Feigler') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">

        <!-- Styles / Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-cream font-sans text-charcoal antialiased">
        <div class="flex min-h-screen flex-col bg-cream">
            <header class="border-b border-sand">
                <div class="mx-auto flex h-20 max-w-6xl items-center justify-between gap-4 px-6 lg:px-8">
                    <a href="/" class="flex shrink-0 items-center gap-3">
                        <x-application-logo class="h-9 w-9 fill-current text-brand-green sm:h-10 sm:w-10" />
                        <span class="font-display text-xl font-semibold tracking-wide text-brand-green sm:text-2xl">
                            Feigler
                        </span>
                    </a>

                    @if (Route::has('login'))
                        <nav class="flex shrink-0 items-center gap-3">
                            @auth
                                <a
                                    href="{{ url('/dashboard') }}"
                                    class="inline-flex items-center whitespace-nowrap rounded-md border border-brand-green bg-brand-green px-4 py-2 text-sm font-medium text-cream transition duration-150 ease-in-out hover:bg-wood focus:outline-hidden focus:ring-2 focus:ring-gold focus:ring-offset-2 focus:ring-offset-cream"
                                >
                                    Panel de control
                                </a>
                            @else
                                <a
                                    href="{{ route('login') }}"
                                    class="hidden items-center whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium text-clay transition duration-150 ease-in-out hover:text-terracotta focus:outline-hidden sm:inline-flex"
                                >
                                    Iniciar sesión
                                </a>

                                @if (Route::has('register'))
                                    <a
                                        href="{{ route('register') }}"
                                        class="inline-flex items-center whitespace-nowrap rounded-md border border-brand-green bg-brand-green px-4 py-2 text-sm font-medium text-cream transition duration-150 ease-in-out hover:bg-wood focus:outline-hidden focus:ring-2 focus:ring-gold focus:ring-offset-2 focus:ring-offset-cream"
                                    >
                                        Registrarse
                                    </a>
                                @endif
                            @endauth
                        </nav>
                    @endif
                </div>
            </header>

            <main class="flex-1">
                <!-- Hero -->
                <section class="mx-auto max-w-6xl px-6 py-16 sm:py-20 lg:px-8 lg:py-28">
                    <div class="max-w-2xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.35em] text-terracotta">
                            Desde el corazón del barrio
                        </p>

                        <h1 class="mt-6 font-display text-4xl font-semibold leading-tight text-brand-green sm:text-5xl lg:text-6xl">
                            Ropa que conserva<br class="hidden sm:block" />
                            el carácter de una época
                        </h1>

                        <p class="mt-6 max-w-xl text-lg leading-relaxed text-clay">
                            Prendas elegidas con calma en un local pequeño: telas nobles, cortes clásicos
                            y piezas que te acompañarán durante muchas temporadas.
                        </p>

                        <div class="mt-10 flex flex-col gap-4 sm:flex-row">
                            <a
                                href="{{ Route::has('login') ? route('login') : '#' }}"
                                class="inline-flex items-center justify-center rounded-md bg-brand-green px-6 py-3 text-sm font-semibold uppercase tracking-widest text-cream transition duration-150 ease-in-out hover:bg-wood focus:outline-hidden focus:ring-2 focus:ring-gold focus:ring-offset-2 focus:ring-offset-cream"
                            >
                                Entrar al panel
                            </a>

                            <a
                                href="#coleccion"
                                class="inline-flex items-center justify-center rounded-md border border-sand bg-parchment px-6 py-3 text-sm font-semibold uppercase tracking-widest text-charcoal transition duration-150 ease-in-out hover:border-terracotta hover:text-terracotta focus:outline-hidden focus:ring-2 focus:ring-gold focus:ring-offset-2 focus:ring-offset-cream"
                            >
                                Ver la colección
                            </a>
                        </div>
                    </div>
                </section>

                <!-- Valores -->
                <section id="coleccion" class="border-y border-sand bg-parchment">
                    <div class="mx-auto grid max-w-6xl gap-10 px-6 py-16 lg:grid-cols-3 lg:px-8">
                        @foreach ([
                            ['Selección curada', 'Cada prenda pasa por una revisión minuciosa, como los buenos muebles de una tienda de barrio.'],
                            ['Cuidado lento', 'Telas nobles y cortes clásicos: piezas pensadas para durar muchas temporadas.'],
                            ['Atención cercana', 'Un local pequeño, recomendaciones honestas y sin prisa por vender.'],
                        ] as [$title, $description])
                            <div class="border-l-2 border-gold/60 pl-6">
                                <h2 class="font-display text-xl font-semibold text-brand-green">
                                    {{ $title }}
                                </h2>

                                <p class="mt-3 text-sm leading-relaxed text-clay">
                                    {{ $description }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </section>
            </main>

            <footer class="bg-wood">
                <div class="mx-auto flex max-w-6xl flex-col gap-6 px-6 py-12 sm:flex-row sm:items-center sm:justify-between lg:px-8">
                    <div class="flex items-center gap-3">
                        <x-application-logo class="h-9 w-9 fill-current text-gold" />
                        <span class="font-display text-xl font-semibold tracking-wide text-cream">
                            Feigler
                        </span>
                    </div>

                    <p class="text-sm text-cream/70">
                        Calidez y buen gusto desde el primer día.
                    </p>
                </div>
            </footer>
        </div>
    </body>
</html>