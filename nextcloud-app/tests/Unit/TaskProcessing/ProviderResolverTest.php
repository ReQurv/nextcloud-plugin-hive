<?php

namespace OCA\RequrvHive\Tests\Unit\TaskProcessing;

use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use OCA\RequrvHive\Service\Provider\ProviderSettingsSchema;
use OCA\RequrvHive\TaskProcessing\ProviderResolver;
use PHPUnit\Framework\TestCase;

class ProviderResolverTest extends TestCase {
    private $factory;
    private ProviderResolver $resolver;

    protected function setUp(): void {
        $this->factory = $this->createMock(LLMProviderFactory::class);
        $this->resolver = new ProviderResolver($this->factory);
    }

    private function provider(string $id, string $label, bool $vision, bool $configured = true) {
        return $this->capable($id, $label, ['vision' => $vision], $configured);
    }

    /** @param array<string, bool> $capabilities */
    private function capable(string $id, string $label, array $capabilities, bool $configured = true) {
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getId')->willReturn($id);
        $provider->method('getLabel')->willReturn($label);
        $provider->method('getCapabilities')->willReturn(ProviderSettingsSchema::capabilities($capabilities));
        $provider->method('isConfigured')->willReturn($configured);
        return $provider;
    }

    public function testResolveDelegatesToTheFactory(): void {
        $epsilon = $this->provider('epsilon', 'Epsilon', false);
        $this->factory->expects($this->once())
            ->method('getProviderForUser')
            ->with('alice', null)
            ->willReturn($epsilon);

        $this->assertSame($epsilon, $this->resolver->resolve('alice'));
    }

    public function testResolvePassesTheRequestedIdThrough(): void {
        $beta = $this->provider('beta', 'Beta', true);
        $this->factory->method('getProviderForUser')->with('alice', 'beta')->willReturn($beta);

        $this->assertSame($beta, $this->resolver->resolve('alice', 'beta'));
    }

    public function testNoPermittedProviderBecomesARuntimeException(): void {
        $this->factory->method('getProviderForUser')
            ->willThrowException(new NoPermittedProviderException('alice'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(NoPermittedProviderException::USER_MESSAGE);
        $this->resolver->resolve('alice');
    }

    public function testRequestedIdIgnoresMissingAndEmptyValues(): void {
        $this->assertNull($this->resolver->requestedId([]));
        $this->assertNull($this->resolver->requestedId(['provider' => '']));
        $this->assertNull($this->resolver->requestedId(['provider' => ['beta']]));
        $this->assertSame('beta', $this->resolver->requestedId(['provider' => 'beta']));
    }

    public function testVisionCapableProviderIsReturnedUnchanged(): void {
        $alpha = $this->provider('alpha', 'Alpha', true);
        $this->factory->method('getProviderForUser')->willReturn($alpha);

        $this->assertSame($alpha, $this->resolver->resolveVisionCapable('alice'));
    }

    public function testNonVisionProviderFailsAndNamesTheAlternatives(): void {
        $epsilon = $this->provider('epsilon', 'Epsilon', false);
        $this->factory->method('getProviderForUser')->willReturn($epsilon);
        $this->factory->method('getProviderIdsForUser')->willReturn(['alpha', 'delta', 'epsilon']);
        $this->factory->method('getProviderById')->willReturnMap([
            ['alpha', $this->provider('alpha', 'Alpha', true)],
            ['delta', $this->provider('delta', 'Delta', false)],
            ['epsilon', $epsilon],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Epsilon cannot process images. Pick a vision-capable provider in your RequrvHive settings — available: Alpha.');
        $this->resolver->resolveVisionCapable('alice');
    }

    public function testUnconfiguredVisionProvidersAreNotOffered(): void {
        $epsilon = $this->provider('epsilon', 'Epsilon', false);
        $this->factory->method('getProviderForUser')->willReturn($epsilon);
        $this->factory->method('getProviderIdsForUser')->willReturn(['alpha', 'epsilon']);
        $this->factory->method('getProviderById')->willReturnMap([
            ['alpha', $this->provider('alpha', 'Alpha', true, false)],
            ['epsilon', $epsilon],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no vision-capable AI provider is available for your account');
        $this->resolver->resolveVisionCapable('alice');
    }

    // ── Non-text modality guards ────────────────────────────────────────────

    public function testAudioCapableProviderIsReturnedUnchanged(): void {
        $beta = $this->capable('beta', 'Beta', ['audio_in' => true]);
        $this->factory->method('getProviderForUser')->willReturn($beta);

        $this->assertSame($beta, $this->resolver->resolveAudioCapable('alice'));
    }

    public function testProviderWithoutTranscriptionFailsAndNamesTheAlternatives(): void {
        $alpha = $this->capable('alpha', 'Alpha', ['vision' => true]);
        $this->factory->method('getProviderForUser')->willReturn($alpha);
        $this->factory->method('getProviderIdsForUser')->willReturn(['alpha', 'beta']);
        $this->factory->method('getProviderById')->willReturnMap([
            ['alpha', $alpha],
            ['beta', $this->capable('beta', 'Beta', ['audio_in' => true])],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Alpha cannot transcribe audio. Pick a transcription-capable provider in your RequrvHive settings — available: Beta.');
        $this->resolver->resolveAudioCapable('alice');
    }

    public function testProviderWithoutSpeechFailsAndNamesTheAlternatives(): void {
        $alpha = $this->capable('alpha', 'Alpha', []);
        $this->factory->method('getProviderForUser')->willReturn($alpha);
        $this->factory->method('getProviderIdsForUser')->willReturn(['alpha', 'beta']);
        $this->factory->method('getProviderById')->willReturnMap([
            ['alpha', $alpha],
            ['beta', $this->capable('beta', 'Beta', ['audio_out' => true])],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Alpha cannot generate speech. Pick a speech-capable provider in your RequrvHive settings — available: Beta.');
        $this->resolver->resolveSpeechCapable('alice');
    }

    public function testProviderWithoutImageGenerationFailsWithNoAlternatives(): void {
        $epsilon = $this->capable('epsilon', 'Epsilon', []);
        $this->factory->method('getProviderForUser')->willReturn($epsilon);
        $this->factory->method('getProviderIdsForUser')->willReturn(['epsilon']);
        $this->factory->method('getProviderById')->willReturnMap([['epsilon', $epsilon]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Epsilon cannot generate images, and no image-generating AI provider is available for your account. Ask your administrator.');
        $this->resolver->resolveImageGenCapable('alice');
    }

    public function testVoiceChatNeedsBothHalvesAndReportsTheMissingOneFirst(): void {
        // Transcription but no speech: the message names the half the run would
        // reach second, not both.
        $half = $this->capable('epsilon', 'Epsilon', ['audio_in' => true]);
        $this->factory->method('getProviderForUser')->willReturn($half);
        $this->factory->method('getProviderIdsForUser')->willReturn(['epsilon']);
        $this->factory->method('getProviderById')->willReturnMap([['epsilon', $half]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Epsilon cannot generate speech, and no speech-capable AI provider is available for your account. Ask your administrator.');
        $this->resolver->resolveVoiceChatCapable('alice');
    }

    public function testVoiceChatAcceptsAProviderWithBothHalves(): void {
        $beta = $this->capable('beta', 'Beta', ['audio_in' => true, 'audio_out' => true]);
        $this->factory->method('getProviderForUser')->willReturn($beta);

        $this->assertSame($beta, $this->resolver->resolveVoiceChatCapable('alice'));
    }
}
