<?php

namespace Phpactor\Extension\LanguageServerRename\Handler;

use Amp\Promise;
use Phpactor\Extension\LanguageServerBridge\Converter\PositionConverter;
use Phpactor\Extension\LanguageServerBridge\Converter\RangeConverter;
use Phpactor\Extension\LanguageServerBridge\Converter\TextEditConverter;
use Phpactor\Extension\LanguageServerRename\Model\Exception\CouldNotRename;
use Phpactor\Extension\LanguageServerRename\Model\LocatedTextEdit;
use Phpactor\Extension\LanguageServerRename\Model\LocatedTextEditsMap;
use Phpactor\Extension\LanguageServerRename\Model\Renamer;
use Phpactor\LanguageServerProtocol\PrepareRenameParams;
use Phpactor\LanguageServerProtocol\PrepareRenameRequest;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\RenameOptions;
use Phpactor\LanguageServerProtocol\RenameParams;
use Phpactor\LanguageServerProtocol\RenameRequest;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentEdit;
use Phpactor\LanguageServerProtocol\VersionedTextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\WorkspaceEdit;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Server\ClientApi;
use Phpactor\LanguageServer\Core\Workspace\Workspace;
use Phpactor\TextDocument\TextDocumentLocator;
use Phpactor\TextDocument\TextDocumentUri;
use function Amp\delay;

class RenameHandler implements Handler, CanRegisterCapabilities
{
    /**
     * @var Renamer
     */
    private $renamer;
    /**
     * @var Workspace
     */
    private $workspace;
    /**
     * @var TextDocumentLocator
     */
    private $documentLocator;

    /**
     * @var ClientApi
     */
    private $clientApi;

    public function __construct(
        Workspace $workspace,
        TextDocumentLocator $documentLocator,
        Renamer $renamer,
        ClientApi $clientApi
    ) {
        $this->renamer = $renamer;
        $this->workspace = $workspace;
        $this->documentLocator = $documentLocator;
        $this->clientApi = $clientApi;
    }
    /**
     * {@inheritDoc}
     */
    public function methods(): array
    {
        return [
            PrepareRenameRequest::METHOD => 'prepareRename',
            RenameRequest::METHOD => 'rename',
        ];
    }

    /**
     * @return Promise<WorkspaceEdit>
     */
    public function rename(RenameParams $params): Promise
    {
        return \Amp\call(function () use ($params) {
            $locatedEdits = [];
            $document = $document = $this->documentLocator->get(TextDocumentUri::fromString($params->textDocument->uri));
            $count = 0;

            try {
                foreach ($this->renamer->rename(
                    $document,
                    PositionConverter::positionToByteOffset(
                        $params->position,
                        (string)$document
                    ),
                    $params->newName
                ) as $result) {
                    if ($count++ === 10) {
                        yield delay(1);
                    }
                    $locatedEdits[] = $result;
                }
            } catch (CouldNotRename $couldNotRename) {
                $this->clientApi->window()->showMessage()->error(sprintf(
                    $couldNotRename->getMessage()
                ));

                return new WorkspaceEdit(null, []);
            }

            return $this->resultToWorkspaceEdit($locatedEdits);
        });
    }
    /**
     * @return Promise<Range>
     */
    public function prepareRename(PrepareRenameParams $params): Promise
    {
        // https://microsoft.github.io/language-server-protocol/specification#textDocument_prepareRename
        return \Amp\call(function () use ($params) {
            $range = $this->renamer->getRenameRange(
                $document = $this->documentLocator->get(TextDocumentUri::fromString($params->textDocument->uri)),
                PositionConverter::positionToByteOffset(
                    $params->position,
                    (string)$document
                ),
            );
            if ($range == null) {
                return null;
            }
            return RangeConverter::toLspRange($range, (string)$document);
        });
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->renameProvider = new RenameOptions(true);
    }

    /**
     * @param LocatedTextEdit[] $locatedEdits
     */
    private function resultToWorkspaceEdit(array $locatedEdits): WorkspaceEdit
    {
        $documentEdits = [];
        $map = LocatedTextEditsMap::fromLocatedEdits($locatedEdits);

        foreach ($map->toLocatedTextEdits() as $result) {
            $version = $this->getDocumentVersion((string)$result->documentUri());
            $documentEdits[] = new TextDocumentEdit(
                new VersionedTextDocumentIdentifier(
                    (string)$result->documentUri(),
                    $version
                ),
                TextEditConverter::toLspTextEdits(
                    $result->textEdits(),
                    (string)$this->documentLocator->get($result->documentUri())
                )
            );
        }
        return new WorkspaceEdit(null, $documentEdits);
    }

    private function getDocumentVersion(string $uri): int
    {
        return $this->workspace->has($uri) ? $this->workspace->get($uri)->version : 0;
    }
}
