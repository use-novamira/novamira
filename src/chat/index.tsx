import { render, useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	Spinner,
} from '@wordpress/components';
import './style.css';

type ChatStatus = {
	available: boolean;
	reason: string;
	message: string;
};

type ChatMessage = {
	id: string;
	role: 'user' | 'assistant' | 'tool';
	content: string;
	attachments?: ChatAttachment[];
	created_at: number;
};

type ChatAttachment = {
	id: string;
	name: string;
	mime_type: string;
	data: string;
	size: number;
};

type ToolRisk = {
	readonly: boolean;
	requires_approval: boolean;
	reason: string;
	must_ask?: boolean;
};

type ToolCall = {
	id: string;
	ability: string;
	arguments: Record<string, unknown>;
	/** The arguments as the card shows them: PHP sent on one line laid out again. */
	display_arguments?: Record<string, unknown>;
	status: string;
	risk: ToolRisk;
	reason?: string;
	result: unknown;
	error: string;
	created_at: number;
	updated_at: number;
};

type ChatSession = {
	id: string;
	provider?: string;
	model?: string;
	status: string;
	created_at: number;
	updated_at: number;
	messages: ChatMessage[];
	tool_calls: ToolCall[];
	allowlist: string[];
	error: string;
};

type ModelDefinition = {
	id: string;
	name: string;
	supports_image_input: boolean;
};

type ProviderDefinition = {
	id: string;
	name: string;
	configured: boolean;
	models: ModelDefinition[];
};

type ModelCatalog = {
	providers: ProviderDefinition[];
	default: {
		provider: string;
		model: string;
	};
};

type ModelOption = {
	key: string;
	providerId: string;
	providerName: string;
	modelId: string;
	modelName: string;
	supportsImageInput: boolean;
	searchText: string;
};

type SpeechRecognitionResultLike = {
	isFinal: boolean;
	[index: number]: {
		transcript: string;
	};
};

type SpeechRecognitionEventLike = {
	resultIndex: number;
	results: {
		length: number;
		[index: number]: SpeechRecognitionResultLike;
	};
};

type SpeechRecognitionErrorEventLike = {
	error: string;
};

type SpeechRecognitionLike = {
	continuous: boolean;
	interimResults: boolean;
	lang: string;
	onend: (() => void) | null;
	onerror: ((event: SpeechRecognitionErrorEventLike) => void) | null;
	onresult: ((event: SpeechRecognitionEventLike) => void) | null;
	start: () => void;
	stop: () => void;
	abort: () => void;
};

type SpeechRecognitionConstructor = new () => SpeechRecognitionLike;

type ActiveChatRun = {
	id: number;
	signal: AbortSignal;
};

declare global {
	interface Window {
		novamiraChat?: {
			root: string;
			nonce: string;
			decisionNonce: string;
			status: ChatStatus;
			connectorsUrl: string;
			consented: boolean;
			backUrl: string;
		};
		SpeechRecognition?: SpeechRecognitionConstructor;
		webkitSpeechRecognition?: SpeechRecognitionConstructor;
	}
}

const config = window.novamiraChat || {
	root: '',
	nonce: '',
	decisionNonce: '',
	status: {
		available: false,
		reason: 'missing_config',
		message: 'Dashboard Chat configuration is missing.',
	},
	connectorsUrl: '',
	consented: false,
	backUrl: '',
};

const MODEL_FAVORITES_STORAGE_KEY = 'novamiraChatModelFavorites';
const SELECTED_MODEL_STORAGE_KEY = 'novamiraChatSelectedModel';

const MAX_IMAGE_BYTES = 3 * 1024 * 1024;
const MAX_ATTACHMENTS_PER_MESSAGE = 4;
const ALLOWED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

async function api<T>(path: string, options: RequestInit = {}): Promise<T> {
	const response = await fetch(`${config.root}${path}`, {
		...options,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
			...(options.headers || {}),
		},
	});
	const body = await response.json().catch(() => ({}));
	if (!response.ok) {
		const message = body?.message || `Request failed with status ${response.status}`;
		throw new Error(message);
	}
	return body as T;
}

function prettyJson(value: unknown): string {
	if (typeof value === 'string') {
		return value;
	}
	try {
		return JSON.stringify(value, null, 2);
	} catch {
		return String(value);
	}
}

// Render tool-call arguments readably: string values (e.g. `code`) are shown
// as-is with real line breaks instead of JSON-escaped "\n".
function formatArguments(value: unknown): string {
	if (value === null || typeof value !== 'object' || Array.isArray(value)) {
		return prettyJson(value);
	}
	const entries = Object.entries(value as Record<string, unknown>);
	if (entries.length === 0) {
		return '{}';
	}
	return entries
		.map(([key, entry]) => `${key}:\n${typeof entry === 'string' ? entry : JSON.stringify(entry, null, 2)}`)
		.join('\n\n');
}

function isAbortError(error: unknown): boolean {
	return (
		(error instanceof DOMException && error.name === 'AbortError') ||
		(error instanceof Error && error.name === 'AbortError')
	);
}

function isTerminalSessionStatus(status: string): boolean {
	return ['completed', 'failed', 'interrupted'].includes(status);
}

function firstLines(value: string, count: number): { text: string; isTruncated: boolean } {
	const lines = value.split(/\r?\n/);
	if (lines.length <= count) {
		return { text: value, isTruncated: false };
	}
	return {
		text: lines.slice(0, count).join('\n'),
		isTruncated: true,
	};
}

function modelSelectionKey(providerId: string, modelId: string): string {
	return JSON.stringify([providerId, modelId]);
}

function readSelectedModelSelection(): { provider: string; model: string } | null {
	try {
		const raw = window.localStorage.getItem(SELECTED_MODEL_STORAGE_KEY);
		const value = raw ? JSON.parse(raw) : null;
		if (
			value &&
			typeof value === 'object' &&
			typeof value.provider === 'string' &&
			typeof value.model === 'string'
		) {
			return { provider: value.provider, model: value.model };
		}
	} catch {
		// Stored model selection is a convenience; fall back to discovery defaults.
	}
	return null;
}

function writeSelectedModelSelection(option: ModelOption): void {
	try {
		window.localStorage.setItem(SELECTED_MODEL_STORAGE_KEY, JSON.stringify({
			provider: option.providerId,
			model: option.modelId,
		}));
	} catch {
		// Selection persistence is a convenience; selection should still work if storage is unavailable.
	}
}

function hasModelSelection(providers: ProviderDefinition[], providerId: string, modelId: string): boolean {
	return providers.some((provider) =>
		provider.id === providerId &&
		provider.configured &&
		provider.models.some((model) => model.id === modelId),
	);
}

function readModelFavorites(): string[] {
	try {
		const raw = window.localStorage.getItem(MODEL_FAVORITES_STORAGE_KEY);
		const value = raw ? JSON.parse(raw) : [];
		return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
	} catch {
		return [];
	}
}

function writeModelFavorites(keys: string[]): void {
	try {
		window.localStorage.setItem(MODEL_FAVORITES_STORAGE_KEY, JSON.stringify(keys));
	} catch {
		// Favorite persistence is a convenience; selection should still work if storage is unavailable.
	}
}

function fuzzyScore(haystack: string, query: string): number | null {
	const normalizedHaystack = haystack.toLowerCase();
	const terms = query.toLowerCase().trim().split(/\s+/).filter(Boolean);
	let total = 0;

	for (const term of terms) {
		const exactIndex = normalizedHaystack.indexOf(term);
		if (exactIndex !== -1) {
			total += exactIndex;
			continue;
		}

		let searchIndex = 0;
		let score = 1000;
		for (const character of term) {
			const foundIndex = normalizedHaystack.indexOf(character, searchIndex);
			if (foundIndex === -1) {
				return null;
			}
			score += foundIndex - searchIndex;
			searchIndex = foundIndex + 1;
		}
		total += score;
	}

	return total;
}

function getSpeechRecognitionConstructor(): SpeechRecognitionConstructor | null {
	return window.SpeechRecognition || window.webkitSpeechRecognition || null;
}

function readImageFile(file: File): Promise<ChatAttachment> {
	return new Promise((resolve, reject) => {
		if (!ALLOWED_IMAGE_MIME_TYPES.includes(file.type)) {
			reject(new Error(`${file.name} must be a JPEG, PNG, WebP, or GIF image.`));
			return;
		}
		if (file.size > MAX_IMAGE_BYTES) {
			reject(new Error(`${file.name} is larger than 3 MB.`));
			return;
		}

		const reader = new FileReader();
		reader.onerror = () => reject(new Error(`Could not read ${file.name}.`));
		reader.onload = () => {
			if (typeof reader.result !== 'string') {
				reject(new Error(`Could not read ${file.name}.`));
				return;
			}
			resolve({
				id: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
				name: file.name,
				mime_type: file.type,
				data: reader.result,
				size: file.size,
			});
		};
		reader.readAsDataURL(file);
	});
}

function App() {
	const [status, setStatus] = useState<ChatStatus>(config.status);
	const [consented, setConsented] = useState<boolean>(config.consented === true);
	const [savingConsent, setSavingConsent] = useState(false);
	const [sessions, setSessions] = useState<ChatSession[]>([]);
	const [activeId, setActiveId] = useState<string>('');
	const [draft, setDraft] = useState('');
	const [providers, setProviders] = useState<ProviderDefinition[]>([]);
	const [selectedProvider, setSelectedProvider] = useState('');
	const [selectedModel, setSelectedModel] = useState('');
	const [favoriteModelKeys, setFavoriteModelKeys] = useState<string[]>(readModelFavorites);
	const [modelsLoading, setModelsLoading] = useState(true);
	const [isBusy, setIsBusy] = useState(false);
	const [supportsVoiceInput, setSupportsVoiceInput] = useState(false);
	const [isListening, setIsListening] = useState(false);
	const [pendingAttachments, setPendingAttachments] = useState<ChatAttachment[]>([]);
	const [pendingMessage, setPendingMessage] = useState<ChatMessage | null>(null);
	const [pendingDeleteId, setPendingDeleteId] = useState<string | null>(null);
	const [isDraggingImage, setIsDraggingImage] = useState(false);
	const [error, setError] = useState('');
	const runningRef = useRef(false);
	const runCounterRef = useRef(0);
	const activeRunRef = useRef<{ id: number; controller: AbortController } | null>(null);
	const recognitionRef = useRef<SpeechRecognitionLike | null>(null);
	const transcriptRef = useRef<HTMLDivElement>(null);
	const layoutRef = useRef<HTMLDivElement>(null);
	const fileInputRef = useRef<HTMLInputElement>(null);

	const activeSession = useMemo(
		() => sessions.find((session) => session.id === activeId) || null,
		[sessions, activeId],
	);

	const configuredProviders = useMemo(
		() => providers.filter((provider) => provider.configured && provider.models.length > 0),
		[providers],
	);

	const modelOptions = useMemo<ModelOption[]>(
		() => configuredProviders.reduce<ModelOption[]>((items, provider) => {
			for (const model of provider.models) {
				items.push({
					key: modelSelectionKey(provider.id, model.id),
					providerId: provider.id,
					providerName: provider.name,
					modelId: model.id,
					modelName: model.name,
					supportsImageInput: model.supports_image_input === true,
					searchText: `${provider.name} ${provider.id} ${model.name} ${model.id}`,
				});
			}
			return items;
		}, []),
		[configuredProviders],
	);

	const selectedModelOption = useMemo(
		() => modelOptions.find((option) => option.providerId === selectedProvider && option.modelId === selectedModel) || null,
		[modelOptions, selectedModel, selectedProvider],
	);

	const activeSessionModelOption = useMemo(
		() => activeSession?.provider && activeSession.model
			? modelOptions.find((option) => option.providerId === activeSession.provider && option.modelId === activeSession.model) || null
			: null,
		[activeSession?.model, activeSession?.provider, modelOptions],
	);

	const attachmentModelOption = activeSession ? activeSessionModelOption : selectedModelOption;
	const canAttachImages = status.available && !modelsLoading && attachmentModelOption?.supportsImageInput === true;
	const imageUnsupportedMessage = activeSession
		? 'This chat model does not support image input.'
		: 'The selected model does not support image input.';

	const hasPendingApproval = useMemo(
		() => activeSession?.tool_calls.some((call) => call.status === 'pending_approval') || false,
		[activeSession],
	);

	const replaceSession = useCallback((session: ChatSession) => {
		setSessions((current) => {
			const rest = current.filter((item) => item.id !== session.id);
			return [session, ...rest].sort((a, b) => b.updated_at - a.updated_at);
		});
		setActiveId(session.id);
	}, []);

	const deleteSession = useCallback(async (sessionId: string) => {
		if (isBusy) {
			return;
		}

		try {
			setError('');
			await api<{ deleted: boolean }>(`/chat/sessions/${sessionId}`, {
				method: 'DELETE',
			});
			const nextSessions = sessions.filter((session) => session.id !== sessionId);
			setSessions(nextSessions);
			setActiveId((current) => current === sessionId ? nextSessions[0]?.id || '' : current);
		} catch (err) {
			setError(err instanceof Error ? err.message : String(err));
		}
	}, [isBusy, sessions]);

	const selectModel = useCallback((option: ModelOption) => {
		setSelectedProvider(option.providerId);
		setSelectedModel(option.modelId);
		writeSelectedModelSelection(option);
	}, []);

	const toggleFavoriteModel = useCallback((option: ModelOption) => {
		setFavoriteModelKeys((current) => {
			const exists = current.includes(option.key);
			const next = exists
				? current.filter((key) => key !== option.key)
				: [...current, option.key];
			writeModelFavorites(next);
			return next;
		});
	}, []);

	const isActiveRun = useCallback((run: ActiveChatRun): boolean => (
		activeRunRef.current?.id === run.id && !run.signal.aborted
	), []);

	const beginChatRun = useCallback((): ActiveChatRun | null => {
		if (runningRef.current || !status.available) {
			return null;
		}

		const controller = new AbortController();
		const id = runCounterRef.current + 1;
		runCounterRef.current = id;
		activeRunRef.current = { id, controller };
		runningRef.current = true;
		setIsBusy(true);
		setError('');

		return { id, signal: controller.signal };
	}, [status.available]);

	const finishChatRun = useCallback((run: ActiveChatRun): void => {
		if (activeRunRef.current?.id !== run.id) {
			return;
		}

		activeRunRef.current = null;
		runningRef.current = false;
		setIsBusy(false);
	}, []);

	const interruptChat = useCallback(() => {
		const activeRun = activeRunRef.current;
		if (!activeRun) {
			return;
		}

		activeRun.controller.abort();
		activeRunRef.current = null;
		runningRef.current = false;
		setIsBusy(false);
		setError('');
	}, []);

	const activeSessionModelLabel = useMemo(() => {
		if (!activeSession?.provider || !activeSession.model) {
			return '';
		}
		return activeSessionModelOption
			? `${activeSessionModelOption.providerName} / ${activeSessionModelOption.modelName}`
			: `${activeSession.provider} / ${activeSession.model}`;
	}, [activeSession?.model, activeSession?.provider, activeSessionModelOption]);

	const refresh = useCallback(async () => {
		const [statusBody, sessionBody] = await Promise.all([
			api<{ available: boolean; reason: string; message: string }>('/chat/status'),
			api<{ sessions: ChatSession[] }>('/chat/sessions'),
		]);
		setStatus(statusBody);
		setSessions(sessionBody.sessions);
		setModelsLoading(true);
		try {
			if (statusBody.available) {
				await api<{ tools: unknown[] }>('/chat/tools').catch(() => undefined);
				const modelBody = await api<ModelCatalog>('/chat/models');
				setProviders(modelBody.providers);
				const storedSelection = readSelectedModelSelection();
				if (
					storedSelection &&
					hasModelSelection(modelBody.providers, storedSelection.provider, storedSelection.model)
				) {
					setSelectedProvider(storedSelection.provider);
					setSelectedModel(storedSelection.model);
				} else if (modelBody.default.provider !== '' && modelBody.default.model !== '') {
					setSelectedProvider(modelBody.default.provider);
					setSelectedModel(modelBody.default.model);
				}
			} else {
				setProviders([]);
			}
		} finally {
			setModelsLoading(false);
		}
		setActiveId((current) => current || sessionBody.sessions[0]?.id || '');
	}, []);

	useEffect(() => {
		refresh().catch((err) => setError(err.message));
	}, [refresh]);

	useEffect(() => {
		setSupportsVoiceInput(getSpeechRecognitionConstructor() !== null);

		return () => {
			const recognition = recognitionRef.current;
			if (recognition) {
				recognition.onend = null;
				recognition.onerror = null;
				recognition.onresult = null;
				recognition.abort();
			}
			recognitionRef.current = null;
		};
	}, []);

	useEffect(() => {
		if (modelOptions.length === 0) {
			setSelectedProvider('');
			setSelectedModel('');
			return;
		}

		if (!selectedModelOption) {
			const fallback = modelOptions[0];
			setSelectedProvider(fallback.providerId);
			setSelectedModel(fallback.modelId);
		}
	}, [modelOptions, selectedModelOption]);

	useEffect(() => {
		if (!activeSession?.provider || !activeSession.model) {
			return;
		}
		if (!hasModelSelection(providers, activeSession.provider, activeSession.model)) {
			return;
		}
		setSelectedProvider(activeSession.provider);
		setSelectedModel(activeSession.model);
		// Sync the picker to the active chat's model only when switching chats.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [activeSession?.id]);

	useEffect(() => {
		transcriptRef.current?.scrollTo({
			top: transcriptRef.current.scrollHeight,
			behavior: 'smooth',
		});
	}, [activeSession?.messages.length, activeSession?.tool_calls.length, activeSession?.id, isBusy, pendingMessage]);

	// Fill the viewport below the layout's top edge instead of a fixed offset, so
	// the chat stays full-height and only the transcript scrolls — never the page —
	// regardless of the admin bar and Novamira header height.
	useEffect(() => {
		const applyHeight = () => {
			const element = layoutRef.current;
			if (!element) {
				return;
			}
			// Use the layout's absolute document position (rect.top + scrollY) so the
			// measurement is independent of how far the page is scrolled. Measuring the
			// raw viewport-relative top while scrolled would inflate the height and make
			// the page grow further — a runaway that leaves a huge blank area.
			const top = element.getBoundingClientRect().top + window.scrollY;
			const available = window.innerHeight - top - 16;
			// Never taller than the viewport, as a backstop against a bad measurement.
			element.style.height = `${Math.max(420, Math.min(window.innerHeight - 24, Math.round(available)))}px`;
		};
		applyHeight();
		// Re-measure once the header logo image has loaded and laid out, which
		// shifts the layout's top edge after the first paint.
		const raf = window.requestAnimationFrame(applyHeight);
		window.addEventListener('resize', applyHeight);
		window.addEventListener('load', applyHeight);
		return () => {
			window.cancelAnimationFrame(raf);
			window.removeEventListener('resize', applyHeight);
			window.removeEventListener('load', applyHeight);
		};
	}, [status.available, modelsLoading, modelOptions.length]);

	useEffect(() => {
		if (pendingAttachments.length === 0 || modelsLoading || !status.available || canAttachImages) {
			return;
		}

		setPendingAttachments([]);
		setError(`${imageUnsupportedMessage} Attached images were removed.`);
	}, [canAttachImages, imageUnsupportedMessage, modelsLoading, pendingAttachments.length, status.available]);

	const executeReadyTools = useCallback(
		async (session: ChatSession, run: ActiveChatRun): Promise<ChatSession> => {
			let current = session;
			const executable = current.tool_calls.filter((call) =>
				['approved'].includes(call.status) ||
				(!call.risk.requires_approval && ['pending_approval', 'approved'].includes(call.status)),
			);
			for (const call of executable) {
				if (!isActiveRun(run)) {
					break;
				}
				if (call.status === 'pending_approval' && call.risk.requires_approval) {
					break;
				}

				const body = await api<{ session: ChatSession }>('/chat/tools/execute', {
					method: 'POST',
					signal: run.signal,
					body: JSON.stringify({ session_id: current.id, tool_call_id: call.id }),
				});
				if (!isActiveRun(run)) {
					break;
				}
				current = body.session;
				replaceSession(current);
			}
			return current;
		},
		[isActiveRun, replaceSession],
	);

	const runLoop = useCallback(
		async (initial: ChatSession, run: ActiveChatRun) => {
			if (!status.available || !isActiveRun(run)) {
				return;
			}
			let current = initial;
			try {
				while (true) {
					if (isTerminalSessionStatus(current.status)) {
						break;
					}
					current = await executeReadyTools(current, run);
					if (!isActiveRun(run) || isTerminalSessionStatus(current.status)) {
						break;
					}
					if (current.tool_calls.some((call) => call.status === 'pending_approval')) {
						break;
					}
					const body = await api<{ session: ChatSession }>('/chat/model-step', {
						method: 'POST',
						signal: run.signal,
						body: JSON.stringify({ session_id: current.id }),
					});
					if (!isActiveRun(run)) {
						break;
					}
					current = body.session;
					replaceSession(current);
				}
			} catch (err) {
				if (isAbortError(err) || !isActiveRun(run)) {
					return;
				}
				setError(err instanceof Error ? err.message : String(err));
				await refresh().catch(() => undefined);
			}
		},
		[executeReadyTools, isActiveRun, refresh, replaceSession, status.available],
	);

	const addImageFiles = useCallback(async (files: FileList | File[]) => {
		if (!canAttachImages) {
			setError(`${imageUnsupportedMessage} Select an image-capable model first.`);
			return;
		}

		const imageFiles = Array.from(files).filter((file) => ALLOWED_IMAGE_MIME_TYPES.includes(file.type));
		if (imageFiles.length === 0) {
			setError('Add an image file.');
			return;
		}

		const remaining = MAX_ATTACHMENTS_PER_MESSAGE - pendingAttachments.length;
		if (remaining <= 0) {
			setError(`Attach up to ${MAX_ATTACHMENTS_PER_MESSAGE} images per message.`);
			return;
		}

		try {
			const attachments = await Promise.all(imageFiles.slice(0, remaining).map(readImageFile));
			setPendingAttachments((current) => [...current, ...attachments].slice(0, MAX_ATTACHMENTS_PER_MESSAGE));
			setError(imageFiles.length > remaining ? `Attached the first ${remaining} images.` : '');
		} catch (err) {
			setError(err instanceof Error ? err.message : String(err));
		}
	}, [canAttachImages, imageUnsupportedMessage, pendingAttachments.length]);

	const sendMessage = async () => {
		const message = draft.trim();
		if (message === '' && pendingAttachments.length === 0) {
			return;
		}
		if (pendingAttachments.length > 0 && !canAttachImages) {
			setError(`${imageUnsupportedMessage} Select an image-capable model first.`);
			return;
		}
		recognitionRef.current?.stop();
		const run = beginChatRun();
		if (!run) {
			return;
		}
		// Show the user's message immediately, with the working indicator below it,
		// instead of waiting for the server round-trip to insert it (which jumps).
		setPendingMessage({
			id: 'pending',
			role: 'user',
			content: message,
			attachments: pendingAttachments,
			created_at: Math.floor(Date.now() / 1000),
		});
		try {
			const payload = {
				message,
				attachments: pendingAttachments,
			};
			const body = activeSession
				? await api<{ session: ChatSession }>(`/chat/sessions/${activeSession.id}`, {
					method: 'PATCH',
					signal: run.signal,
					body: JSON.stringify({ ...payload, provider: selectedProvider, model: selectedModel }),
				})
				: await api<{ session: ChatSession }>('/chat/sessions', {
					method: 'POST',
					signal: run.signal,
					body: JSON.stringify({ ...payload, provider: selectedProvider, model: selectedModel }),
				});
			if (!isActiveRun(run)) {
				return;
			}
			setDraft('');
			setPendingAttachments([]);
			replaceSession(body.session);
			setPendingMessage(null);
			await runLoop(body.session, run);
		} catch (err) {
			if (isAbortError(err) || !isActiveRun(run)) {
				return;
			}
			setError(err instanceof Error ? err.message : String(err));
		} finally {
			setPendingMessage(null);
			finishChatRun(run);
		}
	};

	const editMessage = useCallback(async (message: ChatMessage, content: string): Promise<boolean> => {
		if (!activeSession || message.role !== 'user') {
			return false;
		}
		if (content.trim() === '' && (message.attachments || []).length === 0) {
			setError('Message is required.');
			return false;
		}

		recognitionRef.current?.stop();
		const run = beginChatRun();
		if (!run) {
			return false;
		}

		try {
			const body = await api<{ session: ChatSession }>(`/chat/sessions/${activeSession.id}`, {
				method: 'PATCH',
				signal: run.signal,
				body: JSON.stringify({
					message_id: message.id,
					message: content.trim(),
				}),
			});
			if (!isActiveRun(run)) {
				return false;
			}
			replaceSession(body.session);
			await runLoop(body.session, run);
			return true;
		} catch (err) {
			if (isAbortError(err) || !isActiveRun(run)) {
				return false;
			}
			setError(err instanceof Error ? err.message : String(err));
			return false;
		} finally {
			finishChatRun(run);
		}
	}, [activeSession, beginChatRun, finishChatRun, isActiveRun, replaceSession, runLoop]);

	const toggleVoiceInput = () => {
		if (!supportsVoiceInput || isBusy || !status.available) {
			return;
		}

		if (isListening) {
			recognitionRef.current?.stop();
			setIsListening(false);
			return;
		}

		const Recognition = getSpeechRecognitionConstructor();
		if (!Recognition) {
			setSupportsVoiceInput(false);
			return;
		}

		const recognition = new Recognition();
		recognition.continuous = false;
		recognition.interimResults = false;
		recognition.lang = window.navigator.language || 'en-US';
		recognition.onresult = (event) => {
			const segments: string[] = [];
			for (let index = event.resultIndex; index < event.results.length; index += 1) {
				const result = event.results[index];
				if (result?.isFinal && result[0]?.transcript) {
					segments.push(result[0].transcript.trim());
				}
			}

			const transcript = segments.join(' ').trim();
			if (transcript !== '') {
				setDraft((current) => `${current}${current.trim() === '' ? '' : ' '}${transcript}`);
			}
		};
		recognition.onerror = (event) => {
			setIsListening(false);
			if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
				setError('Microphone permission was denied.');
			}
		};
		recognition.onend = () => {
			setIsListening(false);
			recognitionRef.current = null;
		};

		recognitionRef.current = recognition;
		setError('');
		setIsListening(true);
		try {
			recognition.start();
		} catch (err) {
			setIsListening(false);
			recognitionRef.current = null;
			setError(err instanceof Error ? err.message : String(err));
		}
	};

	const approve = (call: ToolCall, decision: string) => {
		void runApproval(call, decision);
	};

	const runApproval = async (call: ToolCall, decision: string) => {
		if (!activeSession) {
			return;
		}
		if (decision === 'deny') {
			try {
				setError('');
				const body = await api<{ session: ChatSession }>('/chat/approvals', {
					method: 'POST',
					body: JSON.stringify({
						session_id: activeSession.id,
						tool_call_id: call.id,
						decision,
						decision_nonce: config.decisionNonce,
					}),
				});
				replaceSession(body.session);
			} catch (err) {
				setError(err instanceof Error ? err.message : String(err));
			}
			return;
		}
		const run = beginChatRun();
		if (!run) {
			return;
		}
		try {
			const body = await api<{ session: ChatSession }>('/chat/approvals', {
				method: 'POST',
				signal: run.signal,
				body: JSON.stringify({
					session_id: activeSession.id,
					tool_call_id: call.id,
					decision,
					decision_nonce: config.decisionNonce,
				}),
			});
			if (!isActiveRun(run)) {
				return;
			}
			replaceSession(body.session);
			await runLoop(body.session, run);
		} catch (err) {
			if (isAbortError(err) || !isActiveRun(run)) {
				return;
			}
			setError(err instanceof Error ? err.message : String(err));
		} finally {
			finishChatRun(run);
		}
	};

	const canStart = status.available && !modelsLoading && selectedProvider !== '' && selectedModel !== '';
	const canSend = activeSession ? status.available && !hasPendingApproval : canStart;
	const canUseVoiceInput = supportsVoiceInput && status.available && !isBusy;
	const selectedModelLabel = selectedModelOption
		? `${selectedModelOption.providerName} / ${selectedModelOption.modelName}`
		: 'No model selected';
	const currentModelLabel = selectedModelLabel || activeSessionModelLabel;
	const canPickMoreImages = status.available && !isBusy && pendingAttachments.length < MAX_ATTACHMENTS_PER_MESSAGE;
	const hasDraftMessage = draft.trim() !== '' || pendingAttachments.length > 0;
	const chatReady = status.available && !modelsLoading && modelOptions.length > 0;

	const acceptConsent = async () => {
		setSavingConsent(true);
		try {
			await api('/chat/consent', { method: 'POST', body: JSON.stringify({}) });
			setConsented(true);
		} catch (err) {
			setError(err instanceof Error ? err.message : String(err));
		} finally {
			setSavingConsent(false);
		}
	};

	if (!consented) {
		return (
			<div className="novamira-chat">
				<div className="novamira-chat__consent">
					<div className="novamira-chat__consent-card">
						<strong>Before you start</strong>
						<p>Novamira Chat runs on your own AI provider API key. Every message and tool call is billed to that key, not a flat subscription.</p>
						<p>If you use an expensive model, large jobs can get expensive fast, so Novamira Chat is best for quick changes on the fly. For heavier work, <a href={config.backUrl}>connect Novamira over MCP</a>, where you can use your subscription instead.</p>
						{error && <Notice status="error" onRemove={() => setError('')}>{error}</Notice>}
						<div className="novamira-chat__consent-actions">
							<Button variant="tertiary" href={config.backUrl}>Go back</Button>
							<Button variant="primary" isBusy={savingConsent} disabled={savingConsent} onClick={acceptConsent}>I understand</Button>
						</div>
					</div>
				</div>
			</div>
		);
	}

	if (!chatReady) {
		return (
			<div className="novamira-chat">
				{error && <Notice status="error" onRemove={() => setError('')}>{error}</Notice>}
				<div className={`novamira-chat__unavailable${status.available ? '' : ' novamira-chat__unavailable--alert'}`}>
					{status.available && modelsLoading ? (
						<Spinner />
					) : (
						<>
							<strong>{status.available ? 'Connect an AI provider to start' : 'WordPress 7 required'}</strong>
							<p>
								{status.available
									? 'Novamira Chat runs on your own AI provider. It needs one with a function-calling model. Once it is connected, reload this page.'
									: status.message}
							</p>
							{status.available && (
								<Button variant="primary" href={config.connectorsUrl}>
									Set up a provider in WordPress Connectors
								</Button>
							)}
						</>
					)}
				</div>
			</div>
		);
	}

	return (
		<div className="novamira-chat">
			{error && <Notice status="error" onRemove={() => setError('')}>{error}</Notice>}
			{!status.available && <Notice status="warning" isDismissible={false}>{status.message}</Notice>}
			<div className="novamira-chat__layout" ref={layoutRef}>
				<aside className="novamira-chat__sidebar">
					<div className="novamira-chat__sidebar-head">
						<div className="novamira-chat__sidebar-title">
							<strong>Chats</strong>
							<span>{sessions.length === 1 ? '1 conversation' : `${sessions.length} conversations`}</span>
						</div>
						<Button
							variant="secondary"
							className="novamira-chat__new-chat-button"
							onClick={() => {
								setActiveId('');
								setDraft('');
								setPendingAttachments([]);
							}}
							disabled={isBusy}
						>
							<span aria-hidden="true">+</span>
							New Chat
						</Button>
					</div>
					<div className="novamira-chat__session-list">
						{sessions.length === 0 && <p className="novamira-chat__session-empty">No chats yet.</p>}
						{sessions.map((session) => {
							const title = chatTitle(session);
							return (
								<div
									key={session.id}
									className={`novamira-chat__session ${session.id === activeId ? 'is-active' : ''}`}
								>
									<button
										type="button"
										className="novamira-chat__session-select"
										onClick={() => setActiveId(session.id)}
										disabled={isBusy}
									>
										<span className="novamira-chat__session-title">{title}</span>
										<span className="novamira-chat__session-meta">{chatMeta(session)}</span>
									</button>
									<button
										type="button"
										className="novamira-chat__session-delete"
										onClick={(event) => {
											event.stopPropagation();
											setPendingDeleteId(session.id);
										}}
										disabled={isBusy}
										aria-label={`Delete ${title}`}
										title="Delete chat"
									/>
								</div>
							);
						})}
					</div>
				</aside>
				<main className="novamira-chat__main">
					<div className="novamira-chat__chat">
						<div className="novamira-chat__runbar">
							<div>
								<strong>Novamira Chat</strong>
								<span>{currentModelLabel}</span>
							</div>
							{pendingDeleteId && (
								<Modal
									title="Delete conversation?"
									onRequestClose={() => setPendingDeleteId(null)}
									className="novamira-chat__confirm-modal"
								>
									<p>This conversation will be permanently deleted. This cannot be undone.</p>
									<div className="novamira-chat__confirm-actions">
										<Button variant="tertiary" onClick={() => setPendingDeleteId(null)}>Cancel</Button>
										<Button
											variant="primary"
											isDestructive
											onClick={() => {
												const id = pendingDeleteId;
												setPendingDeleteId(null);
												void deleteSession(id);
											}}
										>
											Delete
										</Button>
									</div>
								</Modal>
							)}
						</div>
						<div className="novamira-chat__transcript" ref={transcriptRef}>
							{activeSession || pendingMessage ? (
								<Timeline session={activeSession} pendingMessage={pendingMessage} onApprove={approve} onEditMessage={editMessage} isBusy={isBusy} />
							) : isBusy ? (
								<div className="novamira-chat__timeline">
									<ChatActivity />
								</div>
							) : (
								<div className="novamira-chat__empty">
									<strong>Welcome to Novamira Chat</strong>
									<p>Another way to use Novamira: instead of connecting an external client over MCP, you work with it right here in your dashboard, on your own API keys, using the AI client built into WordPress 7.</p>
									<Notice status="info" isDismissible={false}>
										Each message is billed to your own API key, so it's best for quick changes. For heavier work, <a href={config.backUrl}>connect Novamira over MCP</a>.
									</Notice>
								</div>
							)}
						</div>
						<div className="novamira-chat__composer">
							<div
								className={`novamira-chat__composer-surface ${isDraggingImage ? 'is-dragging' : ''}`}
								onDragEnter={(event) => {
									if (!canPickMoreImages) {
										return;
									}
									event.preventDefault();
									setIsDraggingImage(true);
								}}
								onDragOver={(event) => {
									if (!canPickMoreImages) {
										return;
									}
									event.preventDefault();
								}}
								onDragLeave={(event) => {
									if (event.currentTarget.contains(event.relatedTarget as Node | null)) {
										return;
									}
									setIsDraggingImage(false);
								}}
								onDrop={(event) => {
									setIsDraggingImage(false);
									if (!canPickMoreImages) {
										return;
									}
									event.preventDefault();
									void addImageFiles(event.dataTransfer.files);
								}}
							>
								{pendingAttachments.length > 0 && (
									<div className="novamira-chat__attachments" aria-label="Attached images">
										{pendingAttachments.map((attachment) => (
											<div className="novamira-chat__attachment" key={attachment.id}>
												<img src={attachment.data} alt={attachment.name} />
												<span>{attachment.name}</span>
												<button
													type="button"
													aria-label={`Remove ${attachment.name}`}
													onClick={() => setPendingAttachments((current) => current.filter((item) => item.id !== attachment.id))}
													disabled={isBusy}
												/>
											</div>
										))}
									</div>
								)}
								<textarea
									className="novamira-chat__prompt"
									aria-label="Message"
									placeholder={activeSession ? 'Message Novamira Chat...' : 'Ask Novamira Chat to change, inspect, or build something on your WordPress...'}
									value={draft}
									onChange={(event) => setDraft(event.target.value)}
									onKeyDown={(event) => {
										if (event.key !== 'Enter' || event.shiftKey || event.altKey || event.nativeEvent.isComposing) {
											return;
										}
										if (!canSend || isBusy || (draft.trim() === '' && pendingAttachments.length === 0)) {
											return;
										}

										event.preventDefault();
										void sendMessage();
									}}
									rows={3}
									disabled={!status.available || isBusy}
								/>
								<input
									ref={fileInputRef}
									type="file"
									accept={ALLOWED_IMAGE_MIME_TYPES.join(',')}
									multiple
									className="novamira-chat__file-input"
									onChange={(event) => {
										if (event.target.files) {
											void addImageFiles(event.target.files);
										}
										event.target.value = '';
									}}
								/>
								<div className="novamira-chat__composer-actions">
									<button
										type="button"
										className="novamira-chat__icon-button novamira-chat__icon-button--add"
										aria-label="Add image"
										title={canAttachImages ? 'Add image' : 'Image input unavailable for this model'}
										onClick={() => {
											if (!canAttachImages) {
												setError(`${imageUnsupportedMessage} Select an image-capable model first.`);
												return;
											}
											fileInputRef.current?.click();
										}}
										disabled={!canPickMoreImages}
									/>
									<div className="novamira-chat__model-controls">
										<ModelSelect
											options={modelOptions}
											selected={selectedModelOption}
											favoriteKeys={favoriteModelKeys}
											loading={modelsLoading}
											disabled={!status.available || isBusy || modelsLoading || modelOptions.length === 0}
											onSelect={selectModel}
											onToggleFavorite={toggleFavoriteModel}
										/>
									</div>
									<div className="novamira-chat__composer-spacer" />
									{supportsVoiceInput && (
										<button
											type="button"
											className={`novamira-chat__icon-button novamira-chat__icon-button--mic ${isListening ? 'is-listening' : ''}`}
											aria-label={isListening ? 'Stop voice input' : 'Start voice input'}
											aria-pressed={isListening}
											title={isListening ? 'Stop voice input' : 'Start voice input'}
											onClick={toggleVoiceInput}
											disabled={!canUseVoiceInput}
										/>
									)}
									<button
										type="button"
										className={`novamira-chat__send-button ${isBusy ? 'is-running' : ''}`}
										aria-label={isBusy ? 'Interrupt' : 'Send message'}
										title={isBusy ? 'Interrupt' : 'Send message'}
										onClick={() => {
											if (isBusy) {
												interruptChat();
												return;
											}
											void sendMessage();
										}}
										disabled={!isBusy && (!canSend || !hasDraftMessage)}
									>
										<span aria-hidden="true">{isBusy ? '' : '↑'}</span>
									</button>
								</div>
							</div>
						</div>
					</div>
				</main>
			</div>
		</div>
	);
}

function chatTitle(session: ChatSession): string {
	const firstUserMessage = session.messages.find((message) => message.role === 'user' && message.content.trim() !== '');
	if (firstUserMessage?.content) {
		return firstUserMessage.content;
	}
	const firstAttachment = session.messages
		.find((message) => message.role === 'user' && (message.attachments || []).length > 0)
		?.attachments?.[0];
	return firstAttachment ? `Image: ${firstAttachment.name}` : 'Untitled chat';
}

function chatMeta(session: ChatSession): string {
	const messageCount = session.messages.length;
	const messageLabel = messageCount === 1 ? '1 message' : `${messageCount} messages`;
	return `${formatSessionTime(session.updated_at)} / ${messageLabel}`;
}

function formatSessionTime(timestamp: number): string {
	if (!Number.isFinite(timestamp) || timestamp <= 0) {
		return 'Recently';
	}

	const date = new Date(timestamp * 1000);
	const now = new Date();
	const yesterday = new Date(now);
	yesterday.setDate(now.getDate() - 1);

	if (date.toDateString() === now.toDateString()) {
		return `Today ${formatTime(date)}`;
	}

	if (date.toDateString() === yesterday.toDateString()) {
		return `Yesterday ${formatTime(date)}`;
	}

	return new Intl.DateTimeFormat(undefined, {
		month: 'short',
		day: 'numeric',
	}).format(date);
}

function formatTime(date: Date): string {
	return new Intl.DateTimeFormat(undefined, {
		hour: 'numeric',
		minute: '2-digit',
	}).format(date);
}

function ModelSelect({
	options,
	selected,
	favoriteKeys,
	loading,
	disabled,
	onSelect,
	onToggleFavorite,
}: {
	options: ModelOption[];
	selected: ModelOption | null;
	favoriteKeys: string[];
	loading: boolean;
	disabled: boolean;
	onSelect: (option: ModelOption) => void;
	onToggleFavorite: (option: ModelOption) => void;
}) {
	const [isOpen, setIsOpen] = useState(false);
	const [query, setQuery] = useState('');
	const searchRef = useRef<HTMLInputElement>(null);
	const favoriteKeySet = useMemo(() => new Set(favoriteKeys), [favoriteKeys]);
	const selectedLabel = selected ? `${selected.providerName} / ${selected.modelName}` : '';
	const placeholder = loading
		? 'Loading models...'
		: options.length === 0
			? 'No native tool-call models available'
			: 'Select a model...';

	const filteredOptions = useMemo(() => {
		const trimmedQuery = query.trim();
		if (trimmedQuery === '') {
			return options;
		}

		return options
			.map((option) => ({
				option,
				score: fuzzyScore(option.searchText, trimmedQuery),
			}))
			.filter((item): item is { option: ModelOption; score: number } => item.score !== null)
			.sort((a, b) => a.score - b.score || a.option.providerName.localeCompare(b.option.providerName) || a.option.modelName.localeCompare(b.option.modelName))
			.map((item) => item.option);
	}, [options, query]);

	const groups = useMemo(() => {
		const favorites = filteredOptions.filter((option) => favoriteKeySet.has(option.key));
		const providerGroups = new Map<string, { label: string; options: ModelOption[] }>();

		for (const option of filteredOptions) {
			if (favoriteKeySet.has(option.key)) {
				continue;
			}
			if (!providerGroups.has(option.providerId)) {
				providerGroups.set(option.providerId, {
					label: option.providerName,
					options: [],
				});
			}
			providerGroups.get(option.providerId)?.options.push(option);
		}

		return [
			...(favorites.length > 0 ? [{ id: 'favorites', label: 'Favorites', options: favorites }] : []),
			...Array.from(providerGroups.entries()).map(([id, group]) => ({ id, ...group })),
		];
	}, [favoriteKeySet, filteredOptions]);

	useEffect(() => {
		if (!isOpen) {
			return undefined;
		}

		searchRef.current?.focus();

		const onDocumentKeyDown = (event: KeyboardEvent) => {
			if (event.key === 'Escape') {
				setIsOpen(false);
				setQuery('');
			}
		};

		document.addEventListener('keydown', onDocumentKeyDown);
		return () => document.removeEventListener('keydown', onDocumentKeyDown);
	}, [isOpen]);

	const chooseOption = (option: ModelOption) => {
		onSelect(option);
		setIsOpen(false);
		setQuery('');
	};

	const toggleFavorite = (event: { preventDefault: () => void; stopPropagation: () => void }, option: ModelOption) => {
		event.preventDefault();
		event.stopPropagation();
		onToggleFavorite(option);
	};

	return (
		<div className="novamira-chat__model-select">
			<button
				id="novamira-chat-model-select-trigger"
				type="button"
				className={`novamira-chat__model-select-control ${isOpen ? 'is-open' : ''}`}
				aria-haspopup="dialog"
				aria-expanded={isOpen}
				disabled={disabled}
				onClick={() => {
					setIsOpen(true);
					setQuery('');
				}}
			>
				<span className={selectedLabel ? '' : 'is-placeholder'}>{selectedLabel || placeholder}</span>
			</button>
			{isOpen && !disabled && (
				<div
					className="novamira-chat__model-select-modal-backdrop"
					onMouseDown={(event) => {
						if (event.currentTarget === event.target) {
							setIsOpen(false);
							setQuery('');
						}
					}}
				>
					<div className="novamira-chat__model-select-modal" role="dialog" aria-modal="true" aria-labelledby="novamira-chat-model-select-title">
						<div className="novamira-chat__model-select-modal-head">
							<div>
								<strong id="novamira-chat-model-select-title">Select model</strong>
								<span>{selectedLabel || 'No model selected'}</span>
							</div>
							<button
								type="button"
								className="novamira-chat__model-select-close"
								aria-label="Close model selection"
								onClick={() => {
									setIsOpen(false);
									setQuery('');
								}}
							/>
						</div>
						<div className="novamira-chat__model-select-search-wrap">
							<input
								ref={searchRef}
								className="novamira-chat__model-select-search"
								type="text"
								value={query}
								placeholder="Search models across all providers..."
								autoComplete="off"
								aria-controls="novamira-chat-model-select-results"
								onChange={(event) => setQuery(event.target.value)}
								onKeyDown={(event) => {
									if (event.key === 'Enter' && filteredOptions[0]) {
										event.preventDefault();
										chooseOption(filteredOptions[0]);
									}
								}}
							/>
							{query !== '' && (
								<button
									type="button"
									className="novamira-chat__model-select-search-clear"
									aria-label="Clear model search"
									onClick={() => {
										setQuery('');
										searchRef.current?.focus();
									}}
								/>
							)}
						</div>
						<div className="novamira-chat__model-select-menu" id="novamira-chat-model-select-results" role="listbox">
							{groups.length === 0 ? (
								<div className="novamira-chat__model-select-empty">
									{loading ? 'Loading models...' : 'No matching models'}
								</div>
							) : groups.map((group) => (
								<div className="novamira-chat__model-select-group" key={group.id}>
									<div className="novamira-chat__model-select-group-label">{group.label}</div>
									{group.options.map((option) => {
										const isSelected = selected?.key === option.key;
										const isFavorite = favoriteKeySet.has(option.key);
										return (
											<div
												className={`novamira-chat__model-select-option ${isSelected ? 'is-selected' : ''}`}
												key={option.key}
												role="option"
												aria-selected={isSelected}
											>
												<button
													type="button"
													className="novamira-chat__model-select-option-main"
													onClick={() => chooseOption(option)}
												>
													<strong>{option.modelName}</strong>
													<span>{option.providerName} / {option.modelId}</span>
												</button>
												<button
													type="button"
													className={`novamira-chat__model-select-favorite ${isFavorite ? 'is-favorite' : ''}`}
													aria-label={`${isFavorite ? 'Remove' : 'Add'} favorite for ${option.providerName} ${option.modelName}`}
													aria-pressed={isFavorite}
													onClick={(event) => toggleFavorite(event, option)}
												/>
											</div>
										);
									})}
								</div>
							))}
						</div>
					</div>
				</div>
			)}
		</div>
	);
}

function Timeline({
	session,
	pendingMessage,
	onApprove,
	onEditMessage,
	isBusy,
}: {
	session: ChatSession | null;
	pendingMessage: ChatMessage | null;
	onApprove: (call: ToolCall, decision: string) => void;
	onEditMessage: (message: ChatMessage, content: string) => Promise<boolean>;
	isBusy: boolean;
}) {
	const entries = session
		? [
			...session.messages
				.filter((message) => message.role !== 'tool' && (message.content.trim() !== '' || (message.attachments || []).length > 0))
				.map((message) => ({
					id: message.id,
					createdAt: message.created_at,
					type: 'message' as const,
					message,
				})),
			...session.tool_calls.map((call) => ({
				id: call.id,
				createdAt: call.created_at,
				type: 'tool_call' as const,
				call,
			})),
		].sort((a, b) => a.createdAt - b.createdAt)
		: [];

	return (
		<div className="novamira-chat__timeline">
			{entries.map((entry) => {
				if (entry.type === 'message') {
					return <MessageBubble key={entry.id} message={entry.message} onEditMessage={onEditMessage} disabled={isBusy} />;
				}
				return <ToolCallCard key={entry.id} call={entry.call} onApprove={onApprove} />;
			})}
			{pendingMessage && (
				<MessageBubble key="pending" message={pendingMessage} onEditMessage={onEditMessage} disabled />
			)}
			{isBusy && <ChatActivity />}
		</div>
	);
}

function ChatActivity() {
	return (
		<div className="novamira-chat__activity" role="status" aria-live="polite" aria-label="Novamira Chat is working">
			<div className="novamira-chat__activity-label">Novamira Chat is working</div>
			<div className="novamira-chat__activity-card">
				<span className="novamira-chat__activity-dot" />
				<span className="novamira-chat__activity-dot" />
				<span className="novamira-chat__activity-dot" />
			</div>
		</div>
	);
}

function MessageBubble({
	message,
	onEditMessage,
	disabled,
}: {
	message: ChatMessage;
	onEditMessage: (message: ChatMessage, content: string) => Promise<boolean>;
	disabled: boolean;
}) {
	const [isEditing, setIsEditing] = useState(false);
	const [editDraft, setEditDraft] = useState(message.content);
	const [isSaving, setIsSaving] = useState(false);
	const attachments = message.attachments || [];
	const canEdit = message.role === 'user' && !disabled;

	useEffect(() => {
		if (!isEditing) {
			setEditDraft(message.content);
		}
	}, [isEditing, message.content]);

	const confirmEdit = async () => {
		if (isSaving || disabled) {
			return;
		}

		setIsEditing(false);
		setIsSaving(true);
		try {
			await onEditMessage(message, editDraft);
		} finally {
			setIsSaving(false);
		}
	};

	return (
		<article className={`novamira-chat__message is-${message.role}`}>
			{canEdit && !isEditing && (
				<Button
					variant="link"
					className="novamira-chat__message-edit"
					aria-label="Edit message"
					title="Edit message"
					onClick={() => {
						setEditDraft(message.content);
						setIsEditing(true);
					}}
				/>
			)}
			{attachments.length > 0 && (
				<div className="novamira-chat__message-attachments">
					{attachments.map((attachment) => (
						attachment.data ? (
							<img key={attachment.id} src={attachment.data} alt={attachment.name} />
						) : (
							<div key={attachment.id} className="novamira-chat__message-attachment-placeholder">
								<strong>{attachment.name}</strong>
								<span>Image no longer available</span>
							</div>
						)
					))}
				</div>
			)}
			{isEditing ? (
				<div className="novamira-chat__message-editor">
					<textarea
						className="novamira-chat__message-edit-textarea"
						value={editDraft}
						onChange={(event) => setEditDraft(event.target.value)}
						onKeyDown={(event) => {
							if (event.key !== 'Enter' || event.shiftKey || event.altKey || event.nativeEvent.isComposing) {
								return;
							}
							event.preventDefault();
							void confirmEdit();
						}}
						rows={Math.min(8, Math.max(3, editDraft.split(/\r?\n/).length))}
						disabled={isSaving || disabled}
						aria-label="Edit message"
					/>
					<div className="novamira-chat__message-edit-actions">
						<Button
							variant="secondary"
							onClick={() => {
								setEditDraft(message.content);
								setIsEditing(false);
							}}
							disabled={isSaving || disabled}
						>
							Cancel
						</Button>
						<Button
							variant="primary"
							onClick={() => void confirmEdit()}
							disabled={isSaving || disabled || (editDraft.trim() === '' && attachments.length === 0)}
						>
							Confirm edit
						</Button>
					</div>
				</div>
			) : message.content.trim() !== '' && (
				message.role === 'assistant'
					? <MarkdownMessage content={message.content} />
					: <pre>{message.content}</pre>
			)}
		</article>
	);
}

function MarkdownMessage({ content }: { content: string }) {
	return (
		<div className="novamira-chat__message-content">
			{renderMarkdownBlocks(content)}
		</div>
	);
}

function renderMarkdownBlocks(content: string) {
	const lines = content.replace(/\r\n?/g, '\n').split('\n');
	const blocks = [];
	let index = 0;
	let blockIndex = 0;

	while (index < lines.length) {
		const line = lines[index];

		if (line.trim() === '') {
			index += 1;
			continue;
		}

		const fenceMatch = line.match(/^```([A-Za-z0-9_-]+)?\s*$/);
		if (fenceMatch) {
			const codeLines = [];
			index += 1;
			while (index < lines.length && !lines[index].match(/^```\s*$/)) {
				codeLines.push(lines[index]);
				index += 1;
			}
			if (index < lines.length) {
				index += 1;
			}
			const language = fenceMatch[1] || '';
			blocks.push(
				<pre key={`block-${blockIndex++}`}>
					<code className={language ? `language-${language}` : undefined}>{codeLines.join('\n')}</code>
				</pre>
			);
			continue;
		}

		const headingMatch = line.match(/^(#{1,6})\s+(.+?)\s*#*\s*$/);
		if (headingMatch) {
			const HeadingTag = `h${headingMatch[1].length}` as keyof JSX.IntrinsicElements;
			blocks.push(
				<HeadingTag key={`block-${blockIndex++}`}>
					{renderMarkdownInline(headingMatch[2], `block-${blockIndex}`)}
				</HeadingTag>
			);
			index += 1;
			continue;
		}

		if (isMarkdownTableStart(lines, index)) {
			const tableLines = [line];
			index += 2;
			while (index < lines.length && looksLikeTableRow(lines[index])) {
				tableLines.push(lines[index]);
				index += 1;
			}
			blocks.push(renderMarkdownTable(tableLines, `block-${blockIndex++}`));
			continue;
		}

		const unorderedMatch = line.match(/^\s*[-*+]\s+(.+)$/);
		const orderedMatch = line.match(/^\s*\d+[.)]\s+(.+)$/);
		if (unorderedMatch || orderedMatch) {
			const isOrdered = Boolean(orderedMatch);
			const items = [];
			while (index < lines.length) {
				const itemMatch = isOrdered
					? lines[index].match(/^\s*\d+[.)]\s+(.+)$/)
					: lines[index].match(/^\s*[-*+]\s+(.+)$/);
				if (!itemMatch) {
					break;
				}
				items.push(itemMatch[1]);
				index += 1;
			}
			const ListTag = isOrdered ? 'ol' : 'ul';
			blocks.push(
				<ListTag key={`block-${blockIndex++}`}>
					{items.map((item, itemIndex) => (
						<li key={itemIndex}>{renderMarkdownInline(item, `block-${blockIndex}-item-${itemIndex}`)}</li>
					))}
				</ListTag>
			);
			continue;
		}

		if (/^>\s?/.test(line)) {
			const quoteLines = [];
			while (index < lines.length && /^>\s?/.test(lines[index])) {
				quoteLines.push(lines[index].replace(/^>\s?/, ''));
				index += 1;
			}
			blocks.push(
				<blockquote key={`block-${blockIndex++}`}>
					{renderMarkdownBlocks(quoteLines.join('\n'))}
				</blockquote>
			);
			continue;
		}

		if (/^\s*(-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
			blocks.push(<hr key={`block-${blockIndex++}`} />);
			index += 1;
			continue;
		}

		const paragraphLines = [line];
		index += 1;
		while (
			index < lines.length &&
			lines[index].trim() !== '' &&
			!isMarkdownBlockStart(lines, index)
		) {
			paragraphLines.push(lines[index]);
			index += 1;
		}
		blocks.push(
			<p key={`block-${blockIndex++}`}>
				{renderMarkdownInline(paragraphLines.join(' '), `block-${blockIndex}`)}
			</p>
		);
	}

	return blocks;
}

function renderMarkdownInline(text: string, keyPrefix: string) {
	const nodes = [];
	let index = 0;
	let nodeIndex = 0;

	while (index < text.length) {
		const nextToken = findNextInlineToken(text, index);

		if (!nextToken) {
			nodes.push(text.slice(index));
			break;
		}

		if (nextToken.start > index) {
			nodes.push(text.slice(index, nextToken.start));
		}

		const key = `${keyPrefix}-inline-${nodeIndex++}`;
		if (nextToken.type === 'code') {
			nodes.push(<code key={key}>{nextToken.content}</code>);
		} else if (nextToken.type === 'link') {
			nodes.push(
				isSafeMarkdownUrl(nextToken.url)
					? (
						<a key={key} href={nextToken.url} target="_blank" rel="noreferrer">
							{renderMarkdownInline(nextToken.content, key)}
						</a>
					)
					: `[${nextToken.content}](${nextToken.url})`
			);
		} else if (nextToken.type === 'strong') {
			nodes.push(<strong key={key}>{renderMarkdownInline(nextToken.content, key)}</strong>);
		} else {
			nodes.push(<em key={key}>{renderMarkdownInline(nextToken.content, key)}</em>);
		}

		index = nextToken.end;
	}

	return nodes;
}

type InlineToken = {
	type: 'code' | 'link' | 'strong' | 'em';
	start: number;
	end: number;
	content: string;
	url?: string;
};

function findNextInlineToken(text: string, startIndex: number): InlineToken | null {
	const candidates = [
		matchInlineCode(text, startIndex),
		matchInlineLink(text, startIndex),
		matchInlineDelimited(text, startIndex, '**', 'strong'),
		matchInlineDelimited(text, startIndex, '__', 'strong'),
		matchInlineDelimited(text, startIndex, '*', 'em'),
		matchInlineDelimited(text, startIndex, '_', 'em'),
	].filter(Boolean) as InlineToken[];

	if (candidates.length === 0) {
		return null;
	}

	return candidates.sort((a, b) => a.start - b.start || a.end - b.end)[0];
}

function matchInlineCode(text: string, startIndex: number): InlineToken | null {
	const start = text.indexOf('`', startIndex);
	if (start === -1) {
		return null;
	}

	const end = text.indexOf('`', start + 1);
	if (end === -1) {
		return null;
	}

	return {
		type: 'code',
		start,
		end: end + 1,
		content: text.slice(start + 1, end),
	};
}

function matchInlineLink(text: string, startIndex: number): InlineToken | null {
	const match = /\[([^\]\n]+)\]\(([^)\s]+)\)/g;
	match.lastIndex = startIndex;
	const result = match.exec(text);
	if (!result) {
		return null;
	}

	return {
		type: 'link',
		start: result.index,
		end: result.index + result[0].length,
		content: result[1],
		url: result[2],
	};
}

function matchInlineDelimited(
	text: string,
	startIndex: number,
	delimiter: string,
	type: 'strong' | 'em'
): InlineToken | null {
	let start = text.indexOf(delimiter, startIndex);
	while (start !== -1) {
		const before = start > 0 ? text[start - 1] : '';
		const afterStart = text[start + delimiter.length] || '';
		if (
			delimiter.length === 1 &&
			(before === delimiter || afterStart === delimiter || (delimiter === '_' && isWordCharacter(before) && isWordCharacter(afterStart)))
		) {
			start = text.indexOf(delimiter, start + delimiter.length);
			continue;
		}

		const contentStart = start + delimiter.length;
		let end = text.indexOf(delimiter, contentStart);
		if (end === -1) {
			return null;
		}

		while (end !== -1) {
			const beforeEnd = text[end - 1] || '';
			const afterEnd = text[end + delimiter.length] || '';
			if (
				text.slice(contentStart, end) !== '' &&
				!(
					delimiter.length === 1 &&
					(afterEnd === delimiter || (delimiter === '_' && isWordCharacter(beforeEnd) && isWordCharacter(afterEnd)))
				)
			) {
				break;
			}
			end = text.indexOf(delimiter, end + delimiter.length);
		}

		if (end === -1) {
			return null;
		}

		return {
			type,
			start,
			end: end + delimiter.length,
			content: text.slice(contentStart, end),
		};
	}

	return null;
}

function isWordCharacter(value: string): boolean {
	return /^[A-Za-z0-9]$/.test(value);
}

function isSafeMarkdownUrl(url: string | undefined): url is string {
	if (!url) {
		return false;
	}

	return /^(https?:|mailto:|\/|#)/i.test(url);
}

function isMarkdownBlockStart(lines: string[], index: number): boolean {
	const line = lines[index];
	return (
		/^```/.test(line) ||
		/^(#{1,6})\s+/.test(line) ||
		/^\s*[-*+]\s+/.test(line) ||
		/^\s*\d+[.)]\s+/.test(line) ||
		/^>\s?/.test(line) ||
		/^\s*(-{3,}|\*{3,}|_{3,})\s*$/.test(line) ||
		isMarkdownTableStart(lines, index)
	);
}

function isMarkdownTableStart(lines: string[], index: number): boolean {
	return (
		index + 1 < lines.length &&
		looksLikeTableRow(lines[index]) &&
		/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(lines[index + 1])
	);
}

function looksLikeTableRow(line: string): boolean {
	return line.includes('|') && line.trim().replace(/^\|/, '').replace(/\|$/, '').includes('|');
}

function renderMarkdownTable(lines: string[], key: string) {
	const headerCells = splitMarkdownTableRow(lines[0]);
	const bodyRows = lines.slice(1).map(splitMarkdownTableRow);

	return (
		<div key={key} className="novamira-chat__message-table">
			<table>
				<thead>
					<tr>
						{headerCells.map((cell, index) => (
							<th key={index}>{renderMarkdownInline(cell, `${key}-head-${index}`)}</th>
						))}
					</tr>
				</thead>
				<tbody>
					{bodyRows.map((row, rowIndex) => (
						<tr key={rowIndex}>
							{row.map((cell, cellIndex) => (
								<td key={cellIndex}>{renderMarkdownInline(cell, `${key}-row-${rowIndex}-${cellIndex}`)}</td>
							))}
						</tr>
					))}
				</tbody>
			</table>
		</div>
	);
}

function splitMarkdownTableRow(line: string): string[] {
	return line
		.trim()
		.replace(/^\|/, '')
		.replace(/\|$/, '')
		.split('|')
		.map((cell) => cell.trim());
}

function ToolCallCard({
	call,
	onApprove,
}: {
	call: ToolCall;
	onApprove: (call: ToolCall, decision: string) => void;
}) {
	const [isExpanded, setIsExpanded] = useState(false);
	const [areArgumentsExpanded, setAreArgumentsExpanded] = useState(false);
	const isComplete = ['succeeded', 'failed', 'denied'].includes(call.status);
	const showDetails = !isComplete || isExpanded;
	const hasResult = showDetails && (isComplete || call.status === 'failed' || call.error !== '');
	const statusLabel = call.status.replace(/_/g, ' ');
	const argumentsJson = formatArguments(call.display_arguments ?? call.arguments);
	const shouldPreviewArguments = call.status === 'pending_approval' && !areArgumentsExpanded;
	const argumentPreview = firstLines(argumentsJson, 3);
	const shownArguments = shouldPreviewArguments ? argumentPreview.text : argumentsJson;
	const canExpandArguments = call.status === 'pending_approval' && argumentPreview.isTruncated;

	return (
		<article className={`novamira-chat__toolcall is-${call.status} ${isComplete ? 'is-collapsed' : ''}`}>
			<header>
				<strong>{call.ability}</strong>
				<div className="novamira-chat__toolcall-actions">
					<span className="novamira-chat__toolcall-status">{statusLabel}</span>
					{isComplete && (
						<Button
							variant="link"
							onClick={() => setIsExpanded((current) => !current)}
							aria-expanded={isExpanded}
						>
							{isExpanded ? 'Hide Details' : 'Details'}
						</Button>
					)}
				</div>
			</header>
			{showDetails && call.reason && (
				<div className="novamira-chat__field">
					<strong>Reason</strong>
					<p>{call.reason}</p>
				</div>
			)}
			{showDetails && (
				<div className={`novamira-chat__grid ${hasResult ? '' : 'is-single'}`}>
					<div>
						<strong>Arguments</strong>
						<pre>
							{shouldPreviewArguments && canExpandArguments ? (
								<>
									{shownArguments}
									{'\n... '}
									<button
										type="button"
										className="novamira-chat__arguments-toggle"
										onClick={() => setAreArgumentsExpanded(true)}
										aria-expanded={areArgumentsExpanded}
										aria-label="Show all arguments"
										title="Show all arguments"
									>
										▾
									</button>
								</>
							) : (
								<>
									{shownArguments}
									{canExpandArguments && (
										<>
											{' '}
											<button
												type="button"
												className="novamira-chat__arguments-toggle"
												onClick={() => setAreArgumentsExpanded(false)}
												aria-expanded={areArgumentsExpanded}
												aria-label="Collapse arguments"
												title="Collapse arguments"
											>
												▴
											</button>
										</>
									)}
								</>
							)}
						</pre>
					</div>
					{hasResult && (
						<div>
							<strong>Result</strong>
							<pre>{call.error || prettyJson(call.result)}</pre>
						</div>
					)}
				</div>
			)}
			{call.status === 'pending_approval' && (
				<div className="novamira-chat__controls">
					<Button variant="primary" onClick={() => onApprove(call, 'approve')}>Approve</Button>
					<Button variant="secondary" isDestructive onClick={() => onApprove(call, 'deny')}>Deny</Button>
				</div>
			)}
		</article>
	);
}

const root = document.getElementById('novamira-chat-root');
if (root) {
	render(<App />, root);
}
